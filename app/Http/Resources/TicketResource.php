<?php

namespace App\Http\Resources;

use App\Services\SupportConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ticket = $this->resource;
        $agent = $ticket->assignee;
        $available = $agent !== null && $ticket->queue?->is_active
            && app(SupportConfigurationService::class)->isEligible($agent)
            && $ticket->queue->agents()->whereKey($agent->id)->exists();

        return $ticket->only([
            'id', 'tenant_id', 'subject', 'description', 'status', 'priority', 'category_id',
            'queue_id', 'assigned_agent_id', 'contact_id', 'organization_id', 'conversation_id',
            'created_by', 'resolution_summary', 'first_response_at', 'resolved_at', 'closed_at',
            'created_at', 'updated_at',
        ]) + [
            'assignee_available' => (bool) $available,
            'sla' => $ticket->sla === null ? null : (new SlaExecutionResource($ticket->sla))->resolve($request),
        ];
    }
}
