<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalStep extends Model
{
    use TenantScoped;

    protected $fillable = [
        'approval_process_id', 'position', 'name', 'approver_type', 'approver_user_id',
        'approver_role_id', 'approver_permission', 'minimum_approvals', 'decision_mode',
        'due_hours', 'escalation_user_id', 'escalation_role_id', 'conditions',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'minimum_approvals' => 'integer', 'due_hours' => 'integer', 'conditions' => 'array'];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(ApprovalProcess::class, 'approval_process_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    public function escalationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalation_user_id');
    }

    public function escalationRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'escalation_role_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }
}
