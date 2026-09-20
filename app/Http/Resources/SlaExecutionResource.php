<?php

namespace App\Http\Resources;

use App\Services\SlaEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlaExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $execution = $this->resource;

        return $execution->only([
            'id', 'tenant_id', 'ticket_id', 'snapshot', 'status', 'first_response_due_at', 'first_response_at',
            'resolution_due_at', 'last_resolution_due_at', 'resolved_at', 'paused_at', 'resolution_anchor_at',
            'first_response_breached', 'resolution_breached', 'first_response_breached_at', 'resolution_breached_at',
            'created_at', 'updated_at',
        ]) + ['resolution_remaining_seconds' => app(SlaEngine::class)->remaining($execution, now()->toImmutable()->utc()->startOfSecond())];
    }
}
