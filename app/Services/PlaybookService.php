<?php

namespace App\Services;

use App\Jobs\RunAutomationJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Playbook;
use App\Models\PlaybookActionLog;
use App\Models\PlaybookAnswer;
use App\Models\PlaybookExecution;
use App\Models\PlaybookQuestion;
use App\Models\PlaybookSection;
use App\Models\Task;
use App\Models\TenantUser;
use App\Support\AuditService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PlaybookService
{
    /** @var array<string, class-string<Model>> */
    private const ENTITY_MODELS = [
        'contact' => Contact::class,
        'organization' => Organization::class,
        'lead' => Lead::class,
        'deal' => Deal::class,
    ];

    /** @var array<class-string<Model>, array<int, string>> */
    private const UPDATABLE_FIELDS = [
        Contact::class => ['first_name', 'last_name', 'email', 'phone', 'status'],
        Organization::class => ['name', 'legal_name', 'email', 'phone', 'website', 'industry'],
        Lead::class => ['first_name', 'last_name', 'email', 'phone', 'source', 'status', 'score'],
        Deal::class => ['name', 'value', 'currency', 'status', 'forecast_category', 'expected_close_date'],
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RuleConditionEvaluator $conditions,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(Playbook $playbook, array $data, int $userId): Playbook
    {
        return $this->database->transaction(function () use ($playbook, $data, $userId): Playbook {
            $sectionsProvided = array_key_exists('sections', $data);
            $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
            unset($data['sections']);
            $exists = $playbook->exists;
            $old = $exists ? $playbook->getAttributes() : null;

            if (! $exists) {
                $data['created_by'] = $userId;
                $data['version'] ??= 1;
            }
            $playbook->fill($data);
            if ($exists && $playbook->isDirty(['entity_type', 'version']) && $playbook->executions()->exists()) {
                throw ValidationException::withMessages([
                    'playbook' => 'The entity type and version cannot change after a playbook has executions.',
                ]);
            }
            if ($sectionsProvided && $exists && $playbook->executions()->exists()) {
                throw ValidationException::withMessages([
                    'sections' => 'Create a new playbook version before changing a definition that has executions.',
                ]);
            }
            $playbook->save();
            if ($sectionsProvided) {
                $this->replaceSections($playbook, $sections);
            }
            $this->audit->record(
                $exists ? 'playbook_updated' : 'playbook_created',
                $playbook,
                oldValues: $old,
                newValues: $playbook->getAttributes(),
            );

            return $playbook->fresh($this->definitionRelations());
        });
    }

    /** @param array<string, mixed> $overrides */
    public function createVersion(Playbook $source, array $overrides, int $userId): Playbook
    {
        return $this->database->transaction(function () use ($source, $overrides, $userId): Playbook {
            $source = Playbook::query()->with('sections.questions')->lockForUpdate()->findOrFail($source->id);
            $name = $overrides['name'] ?? $source->name;
            $nextVersion = (int) Playbook::query()->where('name', $name)->withTrashed()->max('version') + 1;
            $sections = $source->sections->map(fn (PlaybookSection $section): array => [
                'title' => $section->title,
                'description' => $section->description,
                'position' => $section->position,
                'required' => $section->required,
                'conditions' => $section->conditions,
                'questions' => $section->questions->map(fn (PlaybookQuestion $question): array => [
                    'key' => $question->key,
                    'prompt' => $question->prompt,
                    'help_text' => $question->help_text,
                    'type' => $question->type,
                    'position' => $question->position,
                    'required' => $question->required,
                    'options' => $question->options,
                    'validation' => $question->validation,
                    'score_config' => $question->score_config,
                    'actions' => $question->actions,
                ])->all(),
            ])->all();

            $copy = new Playbook;
            $copy->fill([
                'created_by' => $userId,
                'name' => $name,
                'entity_type' => $overrides['entity_type'] ?? $source->entity_type,
                'version' => $nextVersion,
                'description' => array_key_exists('description', $overrides) ? $overrides['description'] : $source->description,
                'active' => $overrides['active'] ?? false,
                'settings' => array_key_exists('settings', $overrides) ? $overrides['settings'] : $source->settings,
            ])->save();
            $this->replaceSections($copy, is_array($overrides['sections'] ?? null) ? $overrides['sections'] : $sections);
            $this->audit->record(
                'playbook_version_created',
                $copy,
                newValues: ['source_id' => $source->id, 'version' => $copy->version],
            );

            return $copy->fresh($this->definitionRelations());
        });
    }

    /** @param array<string, mixed> $data */
    public function start(array $data, int $userId): PlaybookExecution
    {
        return $this->database->transaction(function () use ($data, $userId): PlaybookExecution {
            $playbook = Playbook::query()->where('active', true)->findOrFail($data['playbook_id']);
            if ($playbook->entity_type !== $data['entity_type']) {
                throw ValidationException::withMessages([
                    'entity_type' => 'The playbook cannot be executed for this entity type.',
                ]);
            }
            $entity = $this->findEntity($data['entity_type'], (int) $data['entity_id']);
            if (isset($data['assigned_to'])) {
                $this->assertActiveTenantMember((int) $playbook->tenant_id, (int) $data['assigned_to']);
            }

            $execution = PlaybookExecution::create([
                'playbook_id' => $playbook->id,
                'executable_type' => $entity->getMorphClass(),
                'executable_id' => $entity->getKey(),
                'assigned_to' => $data['assigned_to'] ?? $userId,
                'started_by' => $userId,
                'status' => 'in_progress',
                'score' => Money::of(0),
                'started_at' => now(),
                'metadata' => $data['metadata'] ?? null,
            ]);
            $this->audit->record('playbook_execution_started', $execution, newValues: $execution->getAttributes());

            return $execution->fresh($this->executionRelations());
        });
    }

    public function answer(PlaybookExecution $execution, int $questionId, mixed $value, int $userId): PlaybookExecution
    {
        return $this->database->transaction(function () use ($execution, $questionId, $value, $userId): PlaybookExecution {
            $execution = PlaybookExecution::query()
                ->with(['playbook', 'executable'])
                ->lockForUpdate()
                ->findOrFail($execution->id);
            if ($execution->status !== 'in_progress') {
                throw ValidationException::withMessages(['execution' => 'Only in-progress executions can receive answers.']);
            }
            $question = PlaybookQuestion::query()
                ->whereHas('section', fn ($query) => $query->where('playbook_id', $execution->playbook_id))
                ->findOrFail($questionId);
            $normalized = $this->validateAnswer($question, $value);
            $answer = PlaybookAnswer::query()
                ->where('playbook_execution_id', $execution->id)
                ->where('playbook_question_id', $question->id)
                ->lockForUpdate()
                ->first() ?? new PlaybookAnswer;
            $exists = $answer->exists;
            $old = $exists ? $answer->getAttributes() : null;
            $answer->fill([
                'playbook_execution_id' => $execution->id,
                'playbook_question_id' => $question->id,
                'answered_by' => $userId,
                'value' => ['value' => $normalized],
                'score' => $this->answerScore($question, $normalized),
                'answered_at' => now(),
            ])->save();

            $actionResults = $this->executeActions($execution, $answer, $question, $normalized, $userId);
            $execution->score = $this->totalScore($execution);
            $execution->save();
            $this->audit->record(
                $exists ? 'playbook_answer_updated' : 'playbook_answer_created',
                $answer,
                oldValues: $old,
                newValues: [
                    'playbook_execution_id' => $execution->id,
                    'playbook_question_id' => $question->id,
                    'score' => $answer->score,
                    'actions' => $actionResults,
                ],
            );

            return $execution->fresh($this->executionRelations());
        });
    }

    public function complete(PlaybookExecution $execution): PlaybookExecution
    {
        return $this->database->transaction(function () use ($execution): PlaybookExecution {
            $execution = PlaybookExecution::query()
                ->with(['playbook.sections.questions', 'executable', 'answers'])
                ->lockForUpdate()
                ->findOrFail($execution->id);
            if ($execution->status === 'completed') {
                return $execution->fresh($this->executionRelations());
            }
            if ($execution->status !== 'in_progress') {
                throw ValidationException::withMessages(['execution' => 'Only in-progress executions can be completed.']);
            }

            $answered = $execution->answers->pluck('playbook_question_id')->map(fn ($id) => (int) $id)->all();
            $missing = [];
            foreach ($execution->playbook->sections as $section) {
                if (($section->conditions ?? []) !== []
                    && ! $this->conditions->matches($section->conditions, $execution->executable)) {
                    continue;
                }
                foreach ($section->questions as $question) {
                    if (($section->required || $question->required) && ! in_array((int) $question->id, $answered, true)) {
                        $missing[] = $question->key;
                    }
                }
            }
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'answers' => 'Required questions are unanswered: '.implode(', ', $missing).'.',
                ]);
            }

            $execution->update([
                'status' => 'completed',
                'score' => $this->totalScore($execution),
                'completed_at' => now(),
                'cancelled_at' => null,
            ]);
            $this->audit->record('playbook_execution_completed', $execution, newValues: ['score' => $execution->score]);

            return $execution->fresh($this->executionRelations());
        });
    }

    public function cancel(PlaybookExecution $execution): PlaybookExecution
    {
        return $this->database->transaction(function () use ($execution): PlaybookExecution {
            $execution = PlaybookExecution::query()->lockForUpdate()->findOrFail($execution->id);
            if ($execution->status === 'completed') {
                throw ValidationException::withMessages(['execution' => 'A completed execution cannot be cancelled.']);
            }
            if ($execution->status !== 'cancelled') {
                $execution->update(['status' => 'cancelled', 'cancelled_at' => now(), 'completed_at' => null]);
                $this->audit->record('playbook_execution_cancelled', $execution, newValues: ['status' => 'cancelled']);
            }

            return $execution->fresh($this->executionRelations());
        });
    }

    public function delete(Playbook $playbook): void
    {
        $this->database->transaction(function () use ($playbook): void {
            $playbook = Playbook::query()->lockForUpdate()->findOrFail($playbook->id);
            if ($playbook->executions()->exists()) {
                throw ValidationException::withMessages(['playbook' => 'A playbook with executions cannot be deleted.']);
            }
            $old = $playbook->getAttributes();
            $playbook->delete();
            $this->audit->record('playbook_deleted', $playbook, oldValues: $old);
        });
    }

    /** @param array<int, array<string, mixed>> $sections */
    private function replaceSections(Playbook $playbook, array $sections): void
    {
        $positions = [];
        $keys = [];
        $playbook->sections()->delete();
        foreach ($sections as $sectionData) {
            $position = (int) ($sectionData['position'] ?? 0);
            if (isset($positions[$position])) {
                throw ValidationException::withMessages(['sections' => 'Section positions must be unique.']);
            }
            $positions[$position] = true;
            $questions = is_array($sectionData['questions'] ?? null) ? $sectionData['questions'] : [];
            unset($sectionData['questions']);
            $section = $playbook->sections()->create($sectionData);
            $questionPositions = [];
            foreach ($questions as $questionData) {
                $questionPosition = (int) ($questionData['position'] ?? 0);
                $key = mb_strtolower((string) ($questionData['key'] ?? ''));
                if (isset($questionPositions[$questionPosition])) {
                    throw ValidationException::withMessages([
                        'questions' => "Question positions must be unique within section {$position}.",
                    ]);
                }
                if (isset($keys[$key])) {
                    throw ValidationException::withMessages(['questions' => 'Question keys must be unique within a playbook.']);
                }
                $questionPositions[$questionPosition] = true;
                $keys[$key] = true;
                $section->questions()->create($questionData);
            }
        }
    }

    private function findEntity(string $type, int $id): Model
    {
        $class = self::ENTITY_MODELS[$type] ?? null;
        if ($class === null) {
            throw ValidationException::withMessages(['entity_type' => 'Unsupported playbook entity type.']);
        }

        return $class::query()->findOrFail($id);
    }

    private function validateAnswer(PlaybookQuestion $question, mixed $value): mixed
    {
        if ($question->required && blank($value) && $value !== false && $value !== 0 && $value !== '0') {
            throw ValidationException::withMessages(['value' => 'This playbook question is required.']);
        }
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = match ($question->type) {
            'text', 'textarea' => $this->stringAnswer($value),
            'number' => $this->numberAnswer($value),
            'select' => $this->selectAnswer($question, $value, false),
            'multiselect' => $this->selectAnswer($question, $value, true),
            'boolean' => $this->booleanAnswer($value),
            'date' => $this->dateAnswer($value),
            default => throw ValidationException::withMessages(['question' => 'Unsupported playbook question type.']),
        };
        $validation = $question->validation ?? [];
        if ($question->type === 'number') {
            if (isset($validation['min']) && Money::compare($normalized, (string) $validation['min']) < 0) {
                throw ValidationException::withMessages(['value' => 'The answer is below the configured minimum.']);
            }
            if (isset($validation['max']) && Money::compare($normalized, (string) $validation['max']) > 0) {
                throw ValidationException::withMessages(['value' => 'The answer exceeds the configured maximum.']);
            }
        }
        if (is_string($normalized)) {
            $length = mb_strlen($normalized);
            if (isset($validation['min_length']) && $length < (int) $validation['min_length']) {
                throw ValidationException::withMessages(['value' => 'The answer is shorter than the configured minimum.']);
            }
            if (isset($validation['max_length']) && $length > (int) $validation['max_length']) {
                throw ValidationException::withMessages(['value' => 'The answer exceeds the configured maximum length.']);
            }
        }

        return $normalized;
    }

    private function stringAnswer(mixed $value): string
    {
        if (! is_string($value) || mb_strlen($value) > 20000) {
            throw ValidationException::withMessages(['value' => 'The answer must be a string of at most 20000 characters.']);
        }

        return trim($value);
    }

    private function numberAnswer(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || (is_float($value) && ! is_finite($value))) {
            throw ValidationException::withMessages(['value' => 'The answer must be a decimal number.']);
        }
        try {
            return Money::of((string) $value);
        } catch (Throwable) {
            throw ValidationException::withMessages(['value' => 'The answer must be a valid decimal number.']);
        }
    }

    private function selectAnswer(PlaybookQuestion $question, mixed $value, bool $multiple): mixed
    {
        if ($multiple && ! is_array($value)) {
            throw ValidationException::withMessages(['value' => 'The answer must be an array of options.']);
        }
        if (! $multiple && ! is_scalar($value)) {
            throw ValidationException::withMessages(['value' => 'The answer must be one configured option.']);
        }
        $allowed = collect($question->options ?? [])->map(function ($option): string {
            return (string) (is_array($option) ? ($option['value'] ?? '') : $option);
        })->all();
        $values = $multiple ? array_values(array_unique(array_map('strval', $value))) : [(string) $value];
        if (array_diff($values, $allowed) !== []) {
            throw ValidationException::withMessages(['value' => 'The answer contains an option that is not configured.']);
        }

        return $multiple ? $values : $values[0];
    }

    private function booleanAnswer(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw ValidationException::withMessages(['value' => 'The answer must be true or false.']);
        }

        return $value;
    }

    private function dateAnswer(mixed $value): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['value' => 'The answer must use YYYY-MM-DD format.']);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['value' => 'The answer must use a valid YYYY-MM-DD date.']);
        }

        return $value;
    }

    private function answerScore(PlaybookQuestion $question, mixed $value): string
    {
        $config = $question->score_config ?? [];
        $score = $config['points'] ?? $config['value'] ?? $config['default'] ?? 0;
        if (is_array($config['mapping'] ?? null)) {
            $key = is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value) : (string) $value);
            $score = $config['mapping'][$key] ?? $score;
        }
        try {
            $score = Money::of(is_int($score) || is_string($score) ? $score : 0);
            if ($question->type === 'number' && isset($config['multiplier'])) {
                $score = Money::multiply($value, (string) $config['multiplier']);
            }

            return $score;
        } catch (Throwable) {
            throw ValidationException::withMessages(['score_config' => 'The configured playbook score is invalid.']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function executeActions(
        PlaybookExecution $execution,
        PlaybookAnswer $answer,
        PlaybookQuestion $question,
        mixed $value,
        int $userId,
    ): array {
        $results = [];
        foreach ($question->actions ?? [] as $index => $action) {
            $type = (string) ($action['type'] ?? '');
            $fingerprint = hash('sha256', json_encode(['index' => $index, 'action' => $action, 'value' => $value], JSON_THROW_ON_ERROR));
            $key = $index.':'.$fingerprint;
            $log = PlaybookActionLog::query()->firstOrCreate(
                ['playbook_answer_id' => $answer->id, 'action_key' => $key],
                [
                    'playbook_execution_id' => $execution->id,
                    'type' => $type,
                    'status' => 'pending',
                    'executed_at' => now(),
                ],
            );
            $replayed = ! $log->wasRecentlyCreated && $log->status === 'completed';
            if ($replayed && ! in_array($type, ['update_field', 'calculate_score'], true)) {
                $results[] = ['type' => $type, 'status' => 'completed', 'replayed' => true];

                continue;
            }

            try {
                $result = $this->executeAction($execution, $type, $action, $value, $userId, $key);
                $log->update(['status' => 'completed', 'result' => $result, 'error' => null, 'executed_at' => now()]);
                $results[] = ['type' => $type, 'status' => 'completed', 'replayed' => $replayed];
            } catch (Throwable $exception) {
                $log->update([
                    'status' => 'failed',
                    'error' => mb_substr($exception->getMessage(), 0, 2000),
                    'executed_at' => now(),
                ]);
                $results[] = ['type' => $type, 'status' => 'failed', 'error' => $log->error];
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $action @return array<string, mixed> */
    private function executeAction(
        PlaybookExecution $execution,
        string $type,
        array $action,
        mixed $value,
        int $userId,
        string $actionKey,
    ): array {
        return match ($type) {
            'update_field' => $this->updateField($execution->executable, $action, $value),
            'calculate_score' => ['score' => $this->totalScore($execution)],
            'trigger_workflow' => $this->triggerWorkflow($execution, $action, $actionKey),
            'create_task' => $this->createTask($execution, $action, $userId),
            default => throw ValidationException::withMessages(['action' => 'Unsupported playbook action type.']),
        };
    }

    /** @param array<string, mixed> $action @return array<string, mixed> */
    private function updateField(Model $entity, array $action, mixed $answerValue): array
    {
        $field = (string) ($action['field'] ?? '');
        $value = array_key_exists('value', $action) ? $action['value'] : $answerValue;
        if (str_starts_with($field, 'custom_fields.')) {
            $key = substr($field, 14);
            if ($key === '' || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,175}$/', $key) !== 1) {
                throw ValidationException::withMessages(['action.field' => 'The custom field path is invalid.']);
            }
            $customFields = $entity->getAttribute('custom_fields') ?? [];
            Arr::set($customFields, $key, $value);
            $entity->setAttribute('custom_fields', $customFields);
        } elseif (! in_array($field, self::UPDATABLE_FIELDS[$entity::class] ?? [], true)) {
            throw ValidationException::withMessages(['action.field' => 'This entity field cannot be updated by a playbook.']);
        } else {
            $entity->setAttribute($field, $value);
        }
        $entity->save();

        return ['entity_type' => $entity->getMorphClass(), 'entity_id' => $entity->getKey(), 'field' => $field];
    }

    /** @param array<string, mixed> $action @return array<string, mixed> */
    private function triggerWorkflow(PlaybookExecution $execution, array $action, string $actionKey): array
    {
        $automation = Automation::query()->where('active', true)->findOrFail($action['automation_id'] ?? null);
        $eventId = 'playbook:'.$execution->id.':'.$actionKey;
        RunAutomationJob::dispatch(
            (int) $execution->tenant_id,
            (int) $automation->id,
            'playbook.answer',
            $eventId,
            $execution->executable::class,
            (int) $execution->executable->getKey(),
        )->onQueue('automations')->afterCommit();

        return ['automation_id' => (int) $automation->id, 'event_id' => $eventId, 'queued' => true];
    }

    /** @param array<string, mixed> $action @return array<string, mixed> */
    private function createTask(PlaybookExecution $execution, array $action, int $userId): array
    {
        $assignedTo = isset($action['assigned_to']) ? (int) $action['assigned_to'] : ($execution->assigned_to ?: null);
        if ($assignedTo !== null) {
            $this->assertActiveTenantMember((int) $execution->tenant_id, $assignedTo);
        }
        $title = trim((string) ($action['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages(['action.title' => 'A create-task action requires a title.']);
        }
        $task = Task::create([
            'title' => $title,
            'assigned_to' => $assignedTo,
            'created_by' => $userId,
            'status' => 'pending',
            'priority' => 'normal',
            'related_type' => $execution->executable->getMorphClass(),
            'related_id' => $execution->executable->getKey(),
            'custom_fields' => ['playbook_execution_id' => $execution->id],
        ]);

        return ['task_id' => (int) $task->id];
    }

    private function totalScore(PlaybookExecution $execution): string
    {
        return Money::of((string) PlaybookAnswer::query()
            ->where('playbook_execution_id', $execution->id)
            ->sum('score'));
    }

    private function assertActiveTenantMember(int $tenantId, int $userId): void
    {
        if (! TenantUser::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'The selected user is not an active tenant member.']);
        }
    }

    /** @return array<int, string> */
    private function definitionRelations(): array
    {
        return ['creator:id,name,email', 'sections.questions'];
    }

    /** @return array<int, string> */
    private function executionRelations(): array
    {
        return [
            'playbook.creator:id,name,email',
            'playbook.sections.questions',
            'executable',
            'assignee:id,name,email',
            'starter:id,name,email',
            'answers.question',
            'answers.answerer:id,name,email',
            'actionLogs',
        ];
    }
}
