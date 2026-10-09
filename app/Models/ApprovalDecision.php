<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalDecision extends Model
{
    use TenantScoped;

    protected $fillable = ['approval_request_id', 'approval_step_id', 'user_id', 'delegated_from_user_id', 'decision', 'comment', 'decided_at', 'metadata'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'metadata' => 'array'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'approval_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from_user_id');
    }
}
