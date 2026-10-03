<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationAssignment extends Model
{
    use TenantScoped;

    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'assigned_user_id', 'assigned_role_id', 'assigned_by', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assigned_role_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
