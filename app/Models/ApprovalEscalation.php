<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalEscalation extends Model
{
    use TenantScoped;

    public const UPDATED_AT = null;

    protected $fillable = ['approval_request_id', 'approval_step_id', 'to_user_id', 'to_role_id', 'reason', 'escalated_at', 'metadata'];

    protected function casts(): array
    {
        return ['escalated_at' => 'datetime', 'metadata' => 'array'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'approval_step_id');
    }
}
