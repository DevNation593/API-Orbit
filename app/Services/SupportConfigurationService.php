<?php

namespace App\Services;

use App\Models\SlaBusinessCalendar;
use App\Models\SlaPolicy;
use App\Models\SupportAgent;
use App\Models\SupportQueue;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SupportConfigurationService
{
    public function __construct(private readonly AuditService $audit) {}

    public function saveAgent(array $data, ?SupportAgent $agent = null): SupportAgent
    {
        return DB::transaction(function () use ($data, $agent): SupportAgent {
            $tenantId = app(TenantContext::class)->requireId();
            if ($agent !== null) {
                $agent = SupportAgent::forTenant($tenantId)->lockForUpdate()->findOrFail($agent->id);
                if (array_key_exists('user_id', $data)) {
                    throw ValidationException::withMessages(['user_id' => 'The agent user cannot be changed.']);
                }
            } else {
                // Serialize duplicate registration for the same user, including the first insert.
                $user = User::whereKey($data['user_id'])->lockForUpdate()->first();
                if ($user === null) {
                    throw ValidationException::withMessages(['user_id' => 'Select an eligible tenant member.']);
                }
                abort_if(SupportAgent::where('user_id', $user->id)->exists(), 409, 'This user is already a support agent.');
                $agent = new SupportAgent(['user_id' => $user->id, 'is_active' => true, 'tenant_id' => $tenantId]);
            }
            $old = $agent->exists ? $this->auditValues($agent) : null;
            if ($agent->exists && array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->assertAgentCanDeactivate($agent);
            }
            if (! $agent->exists || ($data['is_active'] ?? $agent->is_active)) {
                $candidate = new SupportAgent(['tenant_id' => $tenantId, 'user_id' => $agent->user_id, 'is_active' => true]);
                if (! $this->isEligible($candidate)) {
                    throw ValidationException::withMessages(['user_id' => 'Select an active member with tickets.view and tickets.reply.']);
                }
            }
            $agent->fill(Arr::only($data, ['is_active']))->save();
            $this->recordSave($agent, $old);

            return $agent->fresh();
        });
    }

    public function saveCategory(array $data, ?TicketCategory $category = null): TicketCategory
    {
        return DB::transaction(function () use ($data, $category): TicketCategory {
            $tenantId = app(TenantContext::class)->requireId();
            $category = $category === null ? new TicketCategory : TicketCategory::forTenant($tenantId)->lockForUpdate()->findOrFail($category->id);
            $old = $category->exists ? $this->auditValues($category) : null;
            $category->fill(Arr::only($data, ['name', 'description', 'is_active']))->save();
            $this->recordSave($category, $old);

            return $category->fresh();
        });
    }

    public function saveQueue(array $data, ?SupportQueue $queue = null): SupportQueue
    {
        return DB::transaction(function () use ($data, $queue): SupportQueue {
            $tenantId = app(TenantContext::class)->requireId();
            $queue = $queue === null ? new SupportQueue : SupportQueue::forTenant($tenantId)->lockForUpdate()->findOrFail($queue->id);
            $old = $queue->exists ? $this->auditValues($queue) : null;
            if ($queue->exists && array_key_exists('is_active', $data) && ! $data['is_active']) {
                abort_if(Ticket::where('queue_id', $queue->id)->where('status', '!=', 'CLOSED')->exists(), 409, 'Reassign open tickets before deactivating this queue.');
            }
            if (isset($data['sla_policy_id'])) {
                $policy = SlaPolicy::whereKey($data['sla_policy_id'])->lockForUpdate()->first();
                if ($policy === null || ! $policy->is_active) {
                    throw ValidationException::withMessages(['sla_policy_id' => 'Select an active SLA policy in this tenant.']);
                }
            }
            if (isset($data['escalation_agent_id'])) {
                $agent = SupportAgent::whereKey($data['escalation_agent_id'])->lockForUpdate()->first();
                if (! $queue->exists || $agent === null || ! $this->isEligible($agent)
                    || ! $queue->agents()->whereKey($agent->id)->exists()) {
                    throw ValidationException::withMessages(['escalation_agent_id' => 'Select an eligible member of this queue.']);
                }
            }
            $queue->fill(Arr::only($data, ['name', 'description', 'is_active', 'sla_policy_id', 'escalation_agent_id']))->save();
            $this->recordSave($queue, $old);

            return $queue->fresh();
        });
    }

    public function replaceQueueAgents(SupportQueue $queue, array $agentIds): SupportQueue
    {
        Validator::make(['agent_ids' => $agentIds], [
            'agent_ids' => ['present', 'array', 'list', 'max:100'],
            'agent_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();

        return DB::transaction(function () use ($queue, $agentIds): SupportQueue {
            $tenantId = app(TenantContext::class)->requireId();
            $queue = SupportQueue::forTenant($tenantId)->lockForUpdate()->findOrFail($queue->id);
            $current = $queue->agents()->pluck('support_agents.id')->all();
            $requested = array_map('intval', $agentIds);
            $involved = array_unique(array_merge($current, $requested));
            $agents = SupportAgent::whereIn('id', $involved)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($requested as $id) {
                if (! isset($agents[$id]) || ! $this->isEligible($agents[$id])) {
                    throw ValidationException::withMessages(['agent_ids' => 'Select eligible agents in this tenant.']);
                }
            }
            $removed = array_diff($current, $requested);
            abort_if(in_array($queue->escalation_agent_id, $removed), 409, 'Change the escalation agent before removing that member.');
            abort_if(Ticket::where('queue_id', $queue->id)->whereIn('assigned_agent_id', $removed)
                ->where('status', '!=', 'CLOSED')->exists(), 409, 'Reassign open tickets before removing their agents.');
            $pivot = [];
            foreach ($requested as $id) {
                $pivot[$id] = ['tenant_id' => $tenantId];
            }
            $queue->agents()->sync($pivot);
            $this->audit->record('support.queue_agents.replace', $queue, oldValues: ['agent_ids' => $current], newValues: ['agent_ids' => $requested]);

            return $queue->fresh()->load('agents');
        });
    }

    public function delete(Model $model): void
    {
        abort_unless($model instanceof SupportAgent || $model instanceof SupportQueue || $model instanceof TicketCategory
            || $model instanceof SlaPolicy || $model instanceof SlaBusinessCalendar, 409, 'Support history cannot be deleted.');
        try {
            DB::transaction(function () use ($model): void {
                $model = $model::forTenant(app(TenantContext::class)->requireId())->lockForUpdate()->findOrFail($model->getKey());
                $old = $this->auditValues($model);
                $model->delete();
                $this->audit->record('delete', $model, oldValues: $old);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                abort(409, 'This record is referenced. Remove its configuration references or deactivate it instead.');
            }
            throw $exception;
        }
    }

    public function isEligible(SupportAgent $agent): bool
    {
        $tenantId = app(TenantContext::class)->requireId();
        $user = $agent->user;

        return (int) $agent->tenant_id === $tenantId
            && $agent->is_active
            && $user !== null
            && $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->exists()
            && $user->hasPermission('tickets.view', $tenantId)
            && $user->hasPermission('tickets.reply', $tenantId);
    }

    public function assertAssignment(SupportQueue $queue, ?int $agentId): ?SupportAgent
    {
        $tenantId = app(TenantContext::class)->requireId();
        $queue = SupportQueue::forTenant($tenantId)->find($queue->id);
        if ($queue === null || ! $queue->is_active) {
            throw ValidationException::withMessages(['queue_id' => 'Select an active queue in this tenant.']);
        }
        if ($agentId === null) {
            return null;
        }
        $agent = $queue->agents()->whereKey($agentId)->first();
        if ($agent === null || ! $this->isEligible($agent)) {
            throw ValidationException::withMessages(['assigned_agent_id' => 'Select an eligible member of this queue.']);
        }

        return $agent;
    }

    private function assertAgentCanDeactivate(SupportAgent $agent): void
    {
        abort_if(Ticket::where('assigned_agent_id', $agent->id)->where('status', '!=', 'CLOSED')->exists(), 409, 'Reassign open tickets before deactivating this agent.');
        abort_if(SupportQueue::where('escalation_agent_id', $agent->id)->exists(), 409, 'Change escalation responsibilities before deactivating this agent.');
    }

    private function auditValues(Model $model): array
    {
        return Arr::only($model->attributesToArray(), ['id', 'tenant_id', 'name', 'user_id', 'is_active', 'sla_policy_id', 'escalation_agent_id']);
    }

    private function recordSave(Model $model, ?array $old): void
    {
        $model->refresh();
        $this->audit->record($old === null ? 'create' : 'update', $model, oldValues: $old, newValues: $this->auditValues($model));
    }
}
