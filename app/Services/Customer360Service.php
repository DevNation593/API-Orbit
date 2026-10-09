<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\EntityRelation;
use App\Models\FileRecord;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\QuoteActivity;
use App\Models\RecordMerge;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class Customer360Service
{
    /** @return array<string, mixed> */
    public function overview(Contact $contact, User $user, int $limit = 5): array
    {
        $limit = max(1, min($limit, 10));
        $references = $this->references($contact);
        $tagAccess = $user->hasPermission('tags.view');
        $contactRelations = ['owner:id,name,email', 'organizations:id,name,email,phone,website'];
        if ($tagAccess) {
            $contactRelations[] = 'tags:id,name,color';
        }
        $contact->load($contactRelations);
        $modules = [];

        if ($user->hasPermission('organizations.view')) {
            $modules['companies'] = [
                'count' => $contact->organizations->count(),
                'items' => $contact->organizations->take($limit)->values(),
            ];
        }
        if ($user->hasPermission('leads.view')) {
            $query = $this->leadQuery($references);
            $leadRelations = ['owner:id,name'];
            if ($tagAccess) {
                $leadRelations[] = 'tags:id,name,color';
            }
            $modules['leads'] = [
                'count' => (clone $query)->count(),
                'items' => $query->with($leadRelations)
                    ->latest('updated_at')->limit($limit)->get(),
            ];
        }
        if ($user->hasPermission('deals.view')) {
            $query = $this->dealQuery($references);
            $dealRelations = ['pipeline:id,name', 'stage:id,pipeline_id,name,color', 'owner:id,name'];
            if ($tagAccess) {
                $dealRelations[] = 'tags:id,name,color';
            }
            $modules['opportunities'] = [
                'count' => (clone $query)->count(),
                'items' => $query->with($dealRelations)->latest('updated_at')->limit($limit)->get(),
            ];
        }
        if ($user->hasPermission('activities.view')) {
            $query = $this->activityQuery($references);
            $modules['activities'] = [
                'count' => (clone $query)->count(),
                'items' => $query->with('user:id,name')->orderByRaw('COALESCE(occurred_at, created_at) DESC')
                    ->limit($limit)->get(),
            ];
        }
        if ($user->hasPermission('tasks.view')) {
            $query = $this->taskQuery($references);
            $taskRelations = ['assignee:id,name', 'creator:id,name'];
            if ($tagAccess) {
                $taskRelations[] = 'tags:id,name,color';
            }
            $modules['tasks'] = [
                'count' => (clone $query)->count(),
                'items' => $query->with($taskRelations)
                    ->latest('updated_at')->limit($limit)->get(),
            ];
        }
        if ($user->hasPermission('files.view')) {
            $query = $this->fileQuery($references);
            $fileRelations = ['uploader:id,name'];
            if ($tagAccess) {
                $fileRelations[] = 'tags:id,name,color';
            }
            $modules['documents'] = [
                'count' => (clone $query)->count(),
                'items' => $query->with($fileRelations)
                    ->latest('created_at')->limit($limit)->get(),
            ];
        }
        if ($user->hasPermission('relations.view')) {
            $query = $this->relationQuery($references['contact_ids']);
            $modules['relationships'] = [
                'count' => (clone $query)->count(),
                'items' => $query->latest('updated_at')->limit($limit)->get(),
            ];
        }
        if ($user->hasPermission('conversations.view')) {
            $query = Conversation::query()->whereIn('contact_id', $references['contact_ids']);
            $relations = ['inbox:id,name', 'inboxChannel:id,inbox_id,channel,name', 'assignee:id,name'];
            $modules['conversations'] = [
                'count' => (clone $query)->count(),
                'items' => (clone $query)->with($relations)->withCount('messages')
                    ->latest('last_message_at')->limit($limit)->get(),
            ];
            foreach (['emails' => 'email', 'whatsapp' => 'whatsapp'] as $module => $channel) {
                $channelQuery = (clone $query)->where('channel', $channel);
                $modules[$module] = [
                    'count' => (clone $channelQuery)->count(),
                    'items' => $channelQuery->with($relations)->withCount('messages')
                        ->latest('last_message_at')->limit($limit)->get(),
                ];
            }
        }
        if ($user->hasPermission('quotes.view')) {
            $query = Quote::query()->whereIn('id', $references['quote_ids']);
            $modules['quotes'] = [
                'count' => (clone $query)->count(),
                'items' => $query->with(['currency:id,code,symbol,decimal_places', 'owner:id,name'])
                    ->latest('updated_at')->limit($limit)->get(),
            ];
        }
        if ($user->hasPermission('audit.view')) {
            $query = $this->auditQuery($references);
            $modules['audit_events'] = [
                'count' => (clone $query)->count(),
                'items' => $query->with('user:id,name')->latest('created_at')->limit($limit)->get()
                    ->map(fn (AuditLog $log): array => $this->presentAudit($log)),
            ];
        }

        return [
            'contact' => $contact,
            'modules' => $modules,
            'unavailable_modules' => [
                'meetings', 'sms', 'calls', 'tickets',
                'orders', 'invoices', 'payments',
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function timeline(
        Contact $contact,
        User $user,
        array $filters,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $references = $this->references($contact);
        $fetch = $page * $perPage;
        $events = collect();
        $total = 0;

        if (in_array($filters['source'] ?? null, [null, 'activity'], true) && $user->hasPermission('activities.view')) {
            $activityQuery = $this->activityQuery($references)->with('user:id,name');
            if (isset($filters['event']) && ! str_starts_with($filters['event'], 'audit.')) {
                $activityQuery->where('type', $filters['event']);
            } elseif (isset($filters['event'])) {
                $activityQuery->whereRaw('1 = 0');
            }
            $this->applyActivityDates($activityQuery, $filters);
            $total += (clone $activityQuery)->count();
            $events->push(...$activityQuery->orderByRaw('COALESCE(occurred_at, created_at) DESC')
                ->limit($fetch)->get()->map(fn (Activity $activity): array => $this->presentActivity($activity)));
        }

        if (! in_array($filters['source'] ?? null, ['activity', 'audit'], true) && $user->hasPermission('quotes.view')) {
            $quoteActivityQuery = QuoteActivity::query()->whereIn('quote_id', $references['quote_ids'])->with('user:id,name');
            if (isset($filters['event'])) {
                $quoteActivityQuery->where('type', $filters['event']);
            }
            if (isset($filters['from'])) {
                $quoteActivityQuery->where('occurred_at', '>=', $filters['from']);
            }
            if (isset($filters['to'])) {
                $quoteActivityQuery->where('occurred_at', '<=', $filters['to']);
            }
            $total += (clone $quoteActivityQuery)->count();
            $events->push(...$quoteActivityQuery->latest('occurred_at')->limit($fetch)->get()
                ->map(fn (QuoteActivity $activity): array => $this->presentQuoteActivity($activity)));
        }

        if (in_array($filters['source'] ?? null, [null, 'audit'], true) && $user->hasPermission('audit.view')) {
            $auditQuery = $this->auditQuery($references)->with('user:id,name');
            if (isset($filters['event']) && str_starts_with($filters['event'], 'audit.')) {
                $auditQuery->where('action', substr($filters['event'], 6));
            } elseif (isset($filters['event'])) {
                $auditQuery->whereRaw('1 = 0');
            }
            $this->applyAuditDates($auditQuery, $filters);
            $total += (clone $auditQuery)->count();
            $events->push(...$auditQuery->latest('created_at')->limit($fetch)->get()
                ->map(fn (AuditLog $log): array => $this->presentAudit($log)));
        }

        $items = $events->sortByDesc('occurred_at')->values()->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /** @return array<string, array<int, int>> */
    private function references(Contact $contact): array
    {
        $contactIds = collect([(int) $contact->id])->merge(
            RecordMerge::query()->where('entity_type', 'contact')->where('target_id', $contact->id)->pluck('source_id'),
        )->unique()->values();
        $organizationIds = $contact->organizations()->pluck('organizations.id')->map(fn ($id): int => (int) $id);
        $dealIds = Deal::query()->where(function (Builder $query) use ($contact, $organizationIds): void {
            $query->where('contact_id', $contact->id);
            if ($organizationIds->isNotEmpty()) {
                $query->orWhereIn('organization_id', $organizationIds);
            }
        })->pluck('id')->map(fn ($id): int => (int) $id);
        $leadIds = Lead::query()->where(function (Builder $query) use ($contact, $organizationIds): void {
            $query->where('contact_id', $contact->id);
            if ($organizationIds->isNotEmpty()) {
                $query->orWhereIn('organization_id', $organizationIds);
            }
        })->pluck('id')->map(fn ($id): int => (int) $id);
        $quoteIds = Quote::query()->where(function (Builder $query) use ($contact, $organizationIds, $dealIds): void {
            $query->where('contact_id', $contact->id);
            if ($organizationIds->isNotEmpty()) {
                $query->orWhereIn('organization_id', $organizationIds);
            }
            if ($dealIds->isNotEmpty()) {
                $query->orWhereIn('deal_id', $dealIds);
            }
        })->pluck('id')->map(fn ($id): int => (int) $id);

        $base = [
            'contact' => [(int) $contact->id],
            'organization' => $organizationIds->all(),
            'deal' => $dealIds->all(),
            'lead' => $leadIds->all(),
        ];
        $taskIds = $this->relatedQuery(Task::query(), 'related', $base)->pluck('id')->map(fn ($id): int => (int) $id);
        $fileIds = $this->relatedQuery(FileRecord::query(), 'related', $base)->pluck('id')->map(fn ($id): int => (int) $id);

        return [
            'contact_ids' => $contactIds->all(),
            'organization_ids' => $organizationIds->all(),
            'deal_ids' => $dealIds->all(),
            'lead_ids' => $leadIds->all(),
            'quote_ids' => $quoteIds->all(),
            'task_ids' => $taskIds->all(),
            'file_ids' => $fileIds->all(),
        ];
    }

    /** @param array<string, array<int, int>> $references */
    private function dealQuery(array $references): Builder
    {
        return Deal::query()->whereIn('id', $references['deal_ids']);
    }

    /** @param array<string, array<int, int>> $references */
    private function leadQuery(array $references): Builder
    {
        return Lead::query()->whereIn('id', $references['lead_ids']);
    }

    /** @param array<string, array<int, int>> $references */
    private function activityQuery(array $references): Builder
    {
        return $this->relatedQuery(Activity::query(), 'activityable', [
            'contact' => $references['contact_ids'],
            'organization' => $references['organization_ids'],
            'deal' => $references['deal_ids'],
            'lead' => $references['lead_ids'],
            'task' => $references['task_ids'],
            'file' => $references['file_ids'],
        ]);
    }

    /** @param array<string, array<int, int>> $references */
    private function taskQuery(array $references): Builder
    {
        return Task::query()->whereIn('id', $references['task_ids']);
    }

    /** @param array<string, array<int, int>> $references */
    private function fileQuery(array $references): Builder
    {
        return FileRecord::query()->whereIn('id', $references['file_ids']);
    }

    /** @param array<int, int> $contactIds */
    private function relationQuery(array $contactIds): Builder
    {
        return EntityRelation::query()->where(function (Builder $query) use ($contactIds): void {
            $query->where(fn (Builder $from) => $from->where('from_type', 'contact')->whereIn('from_id', $contactIds))
                ->orWhere(fn (Builder $to) => $to->where('to_type', 'contact')->whereIn('to_id', $contactIds));
        });
    }

    /** @param array<string, array<int, int>> $references */
    private function auditQuery(array $references): Builder
    {
        $types = [
            'contacts' => $references['contact_ids'],
            'organizations' => $references['organization_ids'],
            'deals' => $references['deal_ids'],
            'leads' => $references['lead_ids'],
            'tasks' => $references['task_ids'],
            'file_records' => $references['file_ids'],
            'quotes' => $references['quote_ids'],
        ];

        return AuditLog::query()->where(function (Builder $query) use ($types): void {
            foreach ($types as $type => $ids) {
                if ($ids !== []) {
                    $query->orWhere(fn (Builder $entity) => $entity
                        ->where('entity_type', $type)->whereIn('entity_id', array_map('strval', $ids)));
                }
            }
        });
    }

    /** @param array<string, array<int, int>> $types */
    private function relatedQuery(Builder $query, string $prefix, array $types): Builder
    {
        return $query->where(function (Builder $related) use ($prefix, $types): void {
            foreach ($types as $type => $ids) {
                if ($ids !== []) {
                    $related->orWhere(fn (Builder $part) => $part
                        ->where($prefix.'_type', $type)->whereIn($prefix.'_id', $ids));
                }
            }
        });
    }

    /** @param array<string, mixed> $filters */
    private function applyActivityDates(Builder $query, array $filters): void
    {
        if (isset($filters['from'])) {
            $query->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$filters['from']]);
        }
        if (isset($filters['to'])) {
            $query->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$filters['to']]);
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyAuditDates(Builder $query, array $filters): void
    {
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }
    }

    /** @return array<string, mixed> */
    private function presentActivity(Activity $activity): array
    {
        return [
            'id' => 'activity:'.$activity->id,
            'source' => 'activity',
            'event' => $activity->type,
            'subject' => $activity->subject,
            'body' => $activity->body,
            'entity' => ['type' => $activity->activityable_type, 'id' => $activity->activityable_id],
            'actor' => $activity->user,
            'metadata' => $activity->metadata ?? [],
            'occurred_at' => ($activity->occurred_at ?? $activity->created_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentAudit(AuditLog $log): array
    {
        return [
            'id' => 'audit:'.$log->id,
            'source' => 'audit',
            'event' => 'audit.'.$log->action,
            'subject' => ucfirst(str_replace('_', ' ', $log->action)),
            'body' => null,
            'entity' => ['type' => $log->entity_type, 'id' => $log->entity_id],
            'actor' => $log->user,
            'metadata' => ['changed_fields' => $this->changedFields($log)],
            'occurred_at' => $log->created_at->toISOString(),
        ];
    }

    /**
     * Updates store the full model snapshot on both sides, so only the keys whose value differs count as changed.
     *
     * @return array<int, string>
     */
    private function changedFields(AuditLog $log): array
    {
        $old = $log->old_values;
        $new = $log->new_values;
        if ($old === null || $new === null) {
            return array_keys($old ?? $new ?? []);
        }

        return array_keys(array_filter(
            $new,
            fn (mixed $value, string $field): bool => ! array_key_exists($field, $old)
                || $this->comparable($old[$field]) !== $this->comparable($value),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Snapshots mix the database and the request representation of the same value: 7 and "7", 1 and true,
     * or a JSON column re-encoded with another key order. Two strings are never coerced, so "099" and "99" differ.
     */
    private function comparable(mixed $value): mixed
    {
        if (is_string($value) && is_array($decoded = json_decode($value, true))) {
            $value = $decoded;
        }
        if (is_array($value)) {
            ksort($value);

            return array_map(fn (mixed $item): mixed => is_array($item) ? $this->comparable($item) : $item, $value);
        }

        if (is_bool($value)) {
            $value = (int) $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : $value;
    }

    /** @return array<string, mixed> */
    private function presentQuoteActivity(QuoteActivity $activity): array
    {
        return [
            'id' => 'quote:'.$activity->id,
            'source' => 'quote',
            'event' => $activity->type,
            'subject' => ucfirst(str_replace(['.', '_'], [' · ', ' '], $activity->type)),
            'body' => null,
            'entity' => ['type' => 'quote', 'id' => $activity->quote_id],
            'actor' => $activity->user,
            'metadata' => $activity->metadata ?? [],
            'occurred_at' => $activity->occurred_at->toISOString(),
        ];
    }
}
