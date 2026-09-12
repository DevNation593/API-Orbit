<?php

namespace App\Services;

use App\Models\SupportAgent;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TicketCommentService
{
    public function __construct(
        private readonly SupportConfigurationService $support,
        private readonly SlaEngine $sla,
        private readonly AuditService $audit,
    ) {}

    /** @return array{comment: TicketComment, replayed: bool} */
    public function create(Ticket $ticket, array $data, User $actor): array
    {
        return DB::transaction(function () use ($ticket, $data, $actor): array {
            $ticket = Ticket::forTenant(app(TenantContext::class)->requireId())->lockForUpdate()->findOrFail($ticket->id);
            $ticket->setRelation('sla', $ticket->sla()->lockForUpdate()->first());
            $public = $data['visibility'] === 'PUBLIC';
            Gate::forUser($actor)->authorize($public ? 'reply' : 'commentInternal', $ticket);
            if ($public) {
                $agent = SupportAgent::where('user_id', $actor->id)->lockForUpdate()->first();
                if ($agent === null || ! $this->support->isEligible($agent)) {
                    throw ValidationException::withMessages(['visibility' => 'Public responses require an eligible support agent.']);
                }
            }
            $normalized = ['visibility' => $data['visibility'], 'body' => $data['body']];
            $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $existing = $ticket->comments()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'The idempotency key belongs to a different payload.');

                return ['comment' => $existing, 'replayed' => true];
            }
            abort_if($ticket->status === 'CLOSED' || ($public && $ticket->status === 'RESOLVED'), 409, 'This ticket cannot receive a new comment of this visibility.');
            $at = now()->toImmutable()->utc()->startOfSecond();
            $comment = new TicketComment($normalized + [
                'ticket_id' => $ticket->id, 'author_user_id' => $actor->id,
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
            ]);
            $comment->setCreatedAt($at)->setUpdatedAt($at)->save();
            if ($public && $ticket->first_response_at === null) {
                $ticket->first_response_at = $at;
                $ticket->setUpdatedAt($at)->save();
                $this->sla->recordFirstResponse($ticket, $at);
                $this->audit->record('support.ticket.first_response', $ticket, newValues: ['first_response_at' => $at->toIso8601String(), 'comment_id' => $comment->id]);
            } elseif ($public && $ticket->sla !== null) {
                $this->sla->evaluate($ticket->sla, $at);
            }
            $this->audit->record('support.ticket.comment', $comment, newValues: $comment->only(['ticket_id', 'author_user_id', 'visibility']));

            return ['comment' => $comment->fresh(), 'replayed' => false];
        });
    }
}
