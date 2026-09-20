<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\SupportAgent;
use App\Models\SupportQueue;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\AuditService;
use App\Support\SupportCatalog;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function __construct(
        private readonly SupportConfigurationService $support,
        private readonly SlaEngine $sla,
        private readonly AuditService $audit,
    ) {}

    /** @return array{ticket: Ticket, replayed: bool} */
    public function create(array $data, User $actor): array
    {
        app(TenantContext::class)->requireId();
        $normalized = $this->normalizeCreation($data);
        $key = $data['idempotency_key'];
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Ticket::where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }
        try {
            return DB::transaction(function () use ($normalized, $key, $hash, $actor): array {
                $queue = SupportQueue::whereKey($normalized['queue_id'])->lockForUpdate()->first();
                if ($queue === null) {
                    throw ValidationException::withMessages(['queue_id' => 'Select an active support queue in this tenant.']);
                }
                $this->lockAgent($normalized['assigned_agent_id']);
                $this->support->assertAssignment($queue, $normalized['assigned_agent_id']);
                $this->validateReferences($normalized);
                $at = now()->toImmutable()->utc()->startOfSecond();
                $ticket = new Ticket($normalized + [
                    'status' => 'OPEN', 'created_by' => $actor->id, 'idempotency_key' => $key, 'payload_hash' => $hash,
                ]);
                $ticket->setCreatedAt($at)->setUpdatedAt($at)->save();
                $ticket->setRelation('queue', $queue);
                $this->sla->start($ticket, $at);
                $this->audit->record('create', $ticket, newValues: $this->auditValues($ticket));

                return ['ticket' => $ticket->fresh(), 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The failed transaction is rolled back before a concurrent winner is read.
            $existing = Ticket::where('idempotency_key', $key)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $hash);
        }
    }

    public function update(Ticket $ticket, array $data): Ticket
    {
        return DB::transaction(function () use ($ticket, $data): Ticket {
            $ticket = $this->lockTicket($ticket);
            abort_if(in_array($ticket->status, ['RESOLVED', 'CLOSED'], true), 409, 'Reopen an eligible ticket before editing it.');
            $data = Arr::only($data, ['subject', 'description', 'priority', 'category_id', 'contact_id', 'organization_id', 'conversation_id']);
            $this->validateReferences($data, $ticket);
            $old = $this->auditValues($ticket);
            $ticket->fill($data);
            $changedFields = array_keys($ticket->getDirty());
            if ($changedFields !== []) {
                $ticket->setUpdatedAt(now()->toImmutable()->utc()->startOfSecond())->save();
                $this->audit->record('update', $ticket, oldValues: $old, newValues: $this->auditValues($ticket) + ['changed_fields' => $changedFields]);
            }

            return $ticket->fresh();
        });
    }

    public function assign(Ticket $ticket, int $queueId, ?int $agentId): Ticket
    {
        return DB::transaction(function () use ($ticket, $queueId, $agentId): Ticket {
            $ticket = $this->lockTicket($ticket);
            abort_if($ticket->status === 'CLOSED', 409, 'A closed ticket cannot be reassigned.');
            $queue = SupportQueue::whereKey($queueId)->lockForUpdate()->first();
            if ($queue === null) {
                throw ValidationException::withMessages(['queue_id' => 'Select an active support queue in this tenant.']);
            }
            $this->lockAgent($agentId);
            $this->support->assertAssignment($queue, $agentId);
            $currentAgent = $ticket->assigned_agent_id === null ? null : (int) $ticket->assigned_agent_id;
            if ((int) $ticket->queue_id === $queueId && $currentAgent === $agentId) {
                return $ticket;
            }
            $old = $this->auditValues($ticket);
            $ticket->fill(['queue_id' => $queueId, 'assigned_agent_id' => $agentId]);
            $ticket->setUpdatedAt(now()->toImmutable()->utc()->startOfSecond())->save();
            $this->audit->record('support.ticket.assign', $ticket, oldValues: $old, newValues: $this->auditValues($ticket));

            return $ticket->fresh();
        });
    }

    public function changeStatus(Ticket $ticket, string $status, array $data): Ticket
    {
        return DB::transaction(function () use ($ticket, $status, $data): Ticket {
            $ticket = $this->lockTicket($ticket);
            $from = $ticket->status;
            if ($from === $status) {
                return $ticket;
            }
            abort_unless(in_array($status, SupportCatalog::TRANSITIONS[$from] ?? [], true), 409, 'This status transition is not allowed.');
            abort_if(in_array($status, ['WAITING_CUSTOMER', 'RESOLVED'], true) && $ticket->first_response_at === null, 409, 'Record a public response before this transition.');
            $reopening = $from === 'RESOLVED' && $status === 'IN_PROGRESS';
            if ($status === 'RESOLVED') {
                Validator::make($data, ['resolution_summary' => ['required', 'string', 'min:1', 'max:5000']])->validate();
            }
            if ($reopening) {
                Validator::make($data, ['reason' => ['required', 'string', 'min:1', 'max:2000']])->validate();
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            $old = $ticket->only(['status', 'resolution_summary', 'resolved_at', 'closed_at']);
            $this->sla->transition($ticket, $from, $status, $at);
            $ticket->status = $status;
            if ($status === 'RESOLVED') {
                $ticket->resolved_at = $at;
                $ticket->resolution_summary = $data['resolution_summary'];
            } elseif ($reopening) {
                $ticket->resolved_at = null;
                $ticket->resolution_summary = null;
            } elseif ($status === 'CLOSED') {
                $ticket->closed_at = $at;
            }
            $ticket->setUpdatedAt($at)->save();
            $new = $ticket->only(array_keys($old));
            if ($reopening) {
                $new['reason'] = $data['reason'];
            }
            $this->audit->record('support.ticket.status', $ticket, oldValues: $old, newValues: $new);

            return $ticket->fresh();
        });
    }

    private function lockTicket(Ticket $ticket): Ticket
    {
        $ticket = Ticket::forTenant(app(TenantContext::class)->requireId())->lockForUpdate()->findOrFail($ticket->id);
        $ticket->setRelation('sla', $ticket->sla()->lockForUpdate()->first());

        return $ticket;
    }

    private function lockAgent(?int $agentId): void
    {
        if ($agentId !== null) {
            SupportAgent::whereKey($agentId)->lockForUpdate()->first();
        }
    }

    private function validateReferences(array $data, ?Ticket $ticket = null): void
    {
        foreach ([
            'category_id' => TicketCategory::class, 'contact_id' => Contact::class,
            'organization_id' => Organization::class, 'conversation_id' => Conversation::class,
        ] as $field => $class) {
            if (isset($data[$field])) {
                $record = $class::whereKey($data[$field])->lockForUpdate()->first();
                if ($record === null || ($record instanceof TicketCategory && ! $record->is_active)) {
                    throw ValidationException::withMessages([$field => 'Select a valid reference in this tenant.']);
                }
            }
        }
        if ($ticket === null || array_key_exists('contact_id', $data) || array_key_exists('conversation_id', $data)) {
            $contactId = array_key_exists('contact_id', $data) ? $data['contact_id'] : $ticket?->contact_id;
            $conversationId = array_key_exists('conversation_id', $data) ? $data['conversation_id'] : $ticket?->conversation_id;
            if ($contactId !== null && $conversationId !== null) {
                $conversation = Conversation::whereKey($conversationId)->lockForUpdate()->first();
                if ($conversation?->contact_id !== null && (int) $conversation->contact_id !== (int) $contactId) {
                    throw ValidationException::withMessages(['contact_id' => 'The contact must match the linked conversation.']);
                }
            }
        }
    }

    private function normalizeCreation(array $data): array
    {
        $normalized = ['subject' => $data['subject'], 'description' => $data['description'] ?? null, 'priority' => $data['priority'] ?? 'MEDIUM'];
        foreach (['queue_id', 'assigned_agent_id', 'category_id', 'contact_id', 'organization_id', 'conversation_id'] as $field) {
            $normalized[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }

        return $normalized;
    }

    /** @return array{ticket: Ticket, replayed: bool} */
    private function replay(Ticket $ticket, string $hash): array
    {
        abort_unless(hash_equals($ticket->payload_hash, $hash), 409, 'The idempotency key belongs to a different payload.');

        return ['ticket' => $ticket, 'replayed' => true];
    }

    private function auditValues(Ticket $ticket): array
    {
        return $ticket->only(['id', 'tenant_id', 'status', 'priority', 'queue_id', 'assigned_agent_id', 'category_id', 'contact_id', 'organization_id', 'conversation_id']);
    }
}
