<?php

namespace App\Services;

use App\Events\ApprovalCompleted;
use App\Models\ApprovalDecision;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalEscalation;
use App\Models\ApprovalProcess;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\ApprovalStep;
use App\Models\Quote;
use App\Models\QuoteApproval;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\AuditService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class ApprovalEngine
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RuleConditionEvaluator $conditions,
        private readonly NotificationDispatcher $notifications,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveProcess(ApprovalProcess $process, array $data, int $userId): ApprovalProcess
    {
        return $this->database->transaction(function () use ($process, $data, $userId): ApprovalProcess {
            $steps = $data['steps'] ?? null;
            unset($data['steps']);
            $exists = $process->exists;
            if ($exists && is_array($steps) && $process->requests()->exists()) {
                throw ValidationException::withMessages(['steps' => 'Create a new process version instead of changing steps already used by approval requests.']);
            }
            $version = (int) ($data['version'] ?? $process->version ?? 1);
            $duplicate = ApprovalProcess::query()->where('name', $data['name'] ?? $process->name)->where('version', $version);
            if ($exists) {
                $duplicate->where('id', '!=', $process->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['version' => 'This process name and version already exist.']);
            }
            $old = $exists ? $process->getAttributes() : null;
            if (! $exists) {
                $data['created_by'] = $userId;
            }
            $process->fill($data)->save();
            if (is_array($steps)) {
                $process->steps()->delete();
                foreach ($steps as $step) {
                    $process->steps()->create($step);
                }
            }
            $this->audit->record($exists ? 'approval_process_updated' : 'approval_process_created', $process, oldValues: $old, newValues: $process->getAttributes());

            return $process->fresh($this->processRelations());
        });
    }

    /** @param array<string, mixed> $context */
    public function submit(Model $subject, int $requesterId, array $context = []): ?ApprovalRequest
    {
        $type = $subject->getMorphClass();
        $payload = [...$subject->attributesToArray(), ...$context];
        $process = $this->selectProcess($type, $payload);
        if ($process === null) {
            return null;
        }

        $approval = $this->database->transaction(function () use ($subject, $requesterId, $context, $payload, $process): ApprovalRequest {
            $locked = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $existing = ApprovalRequest::query()->where('approvable_type', $subject->getMorphClass())
                ->where('approvable_id', $subject->getKey())->where('status', 'pending')->first();
            if ($existing !== null) {
                return $existing;
            }
            $step = $this->nextApplicableStep($process, 0, $payload);
            if ($step === null) {
                throw ValidationException::withMessages(['approval_process' => 'The selected process has no applicable approval steps.']);
            }
            $request = ApprovalRequest::create([
                'approval_process_id' => $process->id, 'approvable_type' => $subject->getMorphClass(),
                'approvable_id' => $subject->getKey(), 'requested_by' => $requesterId, 'status' => 'pending',
                'current_step_position' => $step->position, 'requested_at' => now(),
                'due_at' => $this->dueAt($step), 'context' => $context, 'snapshot' => $this->snapshot($locked),
            ]);
            if ($locked instanceof Quote) {
                $locked->update(['status' => 'pending_approval', 'issued_at' => now()]);
                QuoteApproval::create([
                    'quote_id' => $locked->id, 'approval_request_id' => $request->id,
                    'status' => 'pending', 'requested_at' => now(),
                ]);
                $locked->activities()->create(['user_id' => $requesterId, 'type' => 'quote.submitted', 'metadata' => ['approval_request_id' => $request->id], 'occurred_at' => now()]);
            }
            $this->audit->record('approval_requested', $request, newValues: [
                'process_id' => $process->id, 'approvable_type' => $request->approvable_type,
                'approvable_id' => $request->approvable_id, 'step' => $step->position,
            ]);

            return $request;
        });
        $this->notifyStep($approval);

        return $approval->fresh($this->requestRelations());
    }

    /** @return array{request: ApprovalRequest, decision: ApprovalDecision, replayed: bool} */
    public function decide(ApprovalRequest $approval, User $user, string $decision, ?string $comment): array
    {
        $completed = false;
        $result = $this->database->transaction(function () use ($approval, $user, $decision, $comment, &$completed): array {
            $request = ApprovalRequest::query()->with('process.steps')->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                $existing = ApprovalDecision::query()->where('approval_request_id', $request->id)->where('user_id', $user->id)->latest('id')->first();
                if ($existing !== null && $existing->decision === $decision) {
                    return ['request' => $request, 'decision' => $existing, 'replayed' => true];
                }
                throw ValidationException::withMessages(['status' => 'This approval request is no longer pending.']);
            }
            $step = $this->currentStep($request);
            $delegatedFrom = $this->assertEligible($request, $step, $user);
            $existing = ApprovalDecision::query()->where('approval_request_id', $request->id)
                ->where('approval_step_id', $step->id)->where('user_id', $user->id)->first();
            if ($existing !== null) {
                if ($existing->decision === $decision) {
                    return ['request' => $request, 'decision' => $existing, 'replayed' => true];
                }
                throw ValidationException::withMessages(['decision' => 'You already decided this approval step.']);
            }
            $record = ApprovalDecision::create([
                'approval_request_id' => $request->id, 'approval_step_id' => $step->id,
                'user_id' => $user->id, 'delegated_from_user_id' => $delegatedFrom,
                'decision' => $decision, 'comment' => $comment, 'decided_at' => now(),
            ]);
            if ($decision === 'reject') {
                $this->finish($request, 'rejected');
                $completed = true;
            } elseif ($this->stepApproved($request, $step)) {
                $next = $this->nextApplicableStep($request->process, $step->position, $request->snapshot ?? []);
                if ($next === null) {
                    $this->finish($request, 'approved');
                    $completed = true;
                } else {
                    $request->update(['current_step_position' => $next->position, 'due_at' => $this->dueAt($next)]);
                }
            }
            $this->audit->record('approval_decided', $record, newValues: [
                'request_id' => $request->id, 'step_id' => $step->id, 'decision' => $decision,
                'delegated_from_user_id' => $delegatedFrom,
            ]);

            return ['request' => $request->fresh($this->requestRelations()), 'decision' => $record, 'replayed' => false];
        });
        if ($completed) {
            ApprovalCompleted::dispatch($result['request']);
        } elseif (! $result['replayed']) {
            $this->notifyStep($result['request']);
        }

        return $result;
    }

    public function cancel(ApprovalRequest $approval, User $user): ApprovalRequest
    {
        return $this->database->transaction(function () use ($approval, $user): ApprovalRequest {
            $request = ApprovalRequest::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only pending approval requests can be cancelled.']);
            }
            $request->update(['status' => 'cancelled', 'decided_at' => now(), 'due_at' => null]);
            if ($request->approvable instanceof Quote) {
                $request->approvable->update(['status' => 'draft']);
                QuoteApproval::query()->where('approval_request_id', $request->id)->update(['status' => 'cancelled', 'decided_at' => now()]);
            }
            $this->audit->record('approval_cancelled', $request, newValues: ['cancelled_by' => $user->id]);

            return $request->fresh($this->requestRelations());
        });
    }

    public function escalateDue(int $limit = 100): int
    {
        $count = 0;
        ApprovalRequest::query()->where('status', 'pending')->whereNotNull('due_at')->where('due_at', '<=', now())
            ->orderBy('due_at')->limit($limit)->get()->each(function (ApprovalRequest $request) use (&$count): void {
                $this->database->transaction(function () use ($request, &$count): void {
                    $locked = ApprovalRequest::query()->with('process.steps')->whereKey($request->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== 'pending' || $locked->due_at === null || $locked->due_at->isFuture()) {
                        return;
                    }
                    $step = $this->currentStep($locked);
                    if ($step->escalation_user_id === null && $step->escalation_role_id === null) {
                        $locked->update(['due_at' => null]);

                        return;
                    }
                    ApprovalEscalation::query()->firstOrCreate(
                        ['approval_request_id' => $locked->id, 'approval_step_id' => $step->id],
                        ['to_user_id' => $step->escalation_user_id, 'to_role_id' => $step->escalation_role_id, 'reason' => 'step_due', 'escalated_at' => now()],
                    );
                    $locked->update(['due_at' => null]);
                    $this->audit->record('approval_escalated', $locked, newValues: ['step_id' => $step->id, 'to_user_id' => $step->escalation_user_id, 'to_role_id' => $step->escalation_role_id]);
                    $count++;
                });
                $this->notifyStep($request->fresh());
            });

        return $count;
    }

    private function selectProcess(string $type, array $payload): ?ApprovalProcess
    {
        $rules = ApprovalRule::query()->with('process.steps')->where('active', true)->orderBy('priority')->orderBy('id')->get();
        foreach ($rules as $rule) {
            if ($rule->process?->active && $rule->process->approvable_type === $type
                && $this->conditions->matches($rule->conditions ?? [], $payload, $rule->match_type, ['event' => $payload])) {
                return $rule->process;
            }
        }

        return ApprovalProcess::query()->with('steps')->where('approvable_type', $type)->where('active', true)
            ->orderBy('priority')->orderByDesc('version')->get()
            ->first(fn (ApprovalProcess $process): bool => $this->conditions->matches($process->conditions ?? [], $payload, $process->match_type, ['event' => $payload]));
    }

    private function currentStep(ApprovalRequest $request): ApprovalStep
    {
        return $request->process->steps->firstWhere('position', $request->current_step_position)
            ?? throw ValidationException::withMessages(['approval_process' => 'The current approval step no longer exists.']);
    }

    private function nextApplicableStep(ApprovalProcess $process, int $afterPosition, array $payload): ?ApprovalStep
    {
        $process->loadMissing('steps');

        return $process->steps->where('position', '>', $afterPosition)
            ->first(fn (ApprovalStep $step): bool => $this->conditions->matches($step->conditions ?? [], $payload, 'all', ['event' => $payload]));
    }

    private function assertEligible(ApprovalRequest $request, ApprovalStep $step, User $user): ?int
    {
        if ($this->eligible($step, $user) || $this->escalatedEligible($request, $step, $user)) {
            return null;
        }
        if ($step->approver_type === 'user' && $step->approver_user_id !== null) {
            $delegation = ApprovalDelegation::query()->where('from_user_id', $step->approver_user_id)
                ->where('to_user_id', $user->id)->where('active', true)
                ->where(fn ($query) => $query->whereNull('approvable_type')->orWhere('approvable_type', $request->approvable_type))
                ->where('starts_at', '<=', now())->where('ends_at', '>=', now())->first();
            if ($delegation !== null) {
                return (int) $step->approver_user_id;
            }
        }
        throw ValidationException::withMessages(['approver' => 'You are not an eligible approver for the current step.']);
    }

    private function eligible(ApprovalStep $step, User $user): bool
    {
        $tenantId = app(TenantContext::class)->requireId();

        return match ($step->approver_type) {
            'user' => (int) $step->approver_user_id === (int) $user->id,
            'role' => TenantUser::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('role_id', $step->approver_role_id)->where('status', 'active')->exists(),
            'permission' => is_string($step->approver_permission) && $user->hasPermission($step->approver_permission),
            default => false,
        };
    }

    private function escalatedEligible(ApprovalRequest $request, ApprovalStep $step, User $user): bool
    {
        $tenantId = app(TenantContext::class)->requireId();
        $escalation = ApprovalEscalation::query()->where('approval_request_id', $request->id)->where('approval_step_id', $step->id)->first();
        if ($escalation === null) {
            return false;
        }

        return (int) $escalation->to_user_id === (int) $user->id
            || ($escalation->to_role_id !== null && TenantUser::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('role_id', $escalation->to_role_id)->where('status', 'active')->exists());
    }

    private function stepApproved(ApprovalRequest $request, ApprovalStep $step): bool
    {
        $approvals = ApprovalDecision::query()->where('approval_request_id', $request->id)
            ->where('approval_step_id', $step->id)->where('decision', 'approve')->count();
        $needed = (int) $step->minimum_approvals;
        if ($step->decision_mode === 'all') {
            $needed = max($needed, count($this->eligibleUserIds($step)));
        }

        return $approvals >= max(1, $needed);
    }

    /** @return array<int, int> */
    private function eligibleUserIds(ApprovalStep $step): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return match ($step->approver_type) {
            'user' => $step->approver_user_id !== null && TenantUser::query()
                ->where('tenant_id', $tenantId)->where('user_id', $step->approver_user_id)->where('status', 'active')->exists()
                    ? [(int) $step->approver_user_id] : [],
            'role' => TenantUser::query()->where('tenant_id', $tenantId)->where('role_id', $step->approver_role_id)->where('status', 'active')->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
            'permission' => TenantUser::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role.permissions', fn ($query) => $query->where('key', $step->approver_permission))->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
            default => [],
        };
    }

    private function finish(ApprovalRequest $request, string $status): void
    {
        $request->update(['status' => $status, 'decided_at' => now(), 'due_at' => null]);
        if ($request->approvable instanceof Quote) {
            $request->approvable->update(['status' => $status === 'approved' ? 'approved' : 'rejected']);
            $request->approvable->activities()->create([
                'user_id' => auth()->id(), 'type' => $status === 'approved' ? 'quote.approved' : 'quote.approval_rejected',
                'metadata' => ['approval_request_id' => $request->id], 'occurred_at' => now(),
            ]);
            QuoteApproval::query()->where('approval_request_id', $request->id)->update(['status' => $status, 'decided_at' => now()]);
        }
    }

    private function notifyStep(ApprovalRequest $request): void
    {
        if ($request->status !== 'pending') {
            return;
        }
        $request->loadMissing('process.steps');
        $step = $this->currentStep($request);
        $userIds = $this->eligibleUserIds($step);
        $escalation = ApprovalEscalation::query()->where('approval_request_id', $request->id)
            ->where('approval_step_id', $step->id)->first();
        if ($escalation?->to_user_id !== null) {
            $userIds[] = (int) $escalation->to_user_id;
        }
        if ($escalation?->to_role_id !== null) {
            $tenantId = app(TenantContext::class)->requireId();
            $userIds = [...$userIds, ...TenantUser::query()->where('tenant_id', $tenantId)
                ->where('role_id', $escalation->to_role_id)->where('status', 'active')
                ->pluck('user_id')->map(fn ($id): int => (int) $id)->all()];
        }
        foreach (array_values(array_unique($userIds)) as $userId) {
            $user = User::query()->find($userId);
            if ($user !== null) {
                $this->notifications->send($user, 'approval.requested', 'Aprobación pendiente', "Se requiere tu decisión en {$request->process->name}.", ['approval_request_id' => $request->id], '/approvals/'.$request->id, 'high');
            }
        }
    }

    private function dueAt(ApprovalStep $step): ?CarbonImmutable
    {
        return $step->due_hours === null ? null : CarbonImmutable::now()->addHours((int) $step->due_hours);
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $model): array
    {
        return collect($model->attributesToArray())->except([
            'acceptance_token', 'acceptance_token_hash', 'acceptance_idempotency_key_hash', 'deleted_at',
        ])->all();
    }

    /** @return array<int, string> */
    private function processRelations(): array
    {
        return ['creator:id,name,email', 'steps.approverUser:id,name,email', 'steps.approverRole:id,name', 'steps.escalationUser:id,name,email', 'steps.escalationRole:id,name'];
    }

    /** @return array<int, string> */
    public function requestRelations(): array
    {
        return ['process.steps', 'requester:id,name,email', 'decisions.user:id,name,email', 'decisions.step:id,name,position', 'escalations'];
    }
}
