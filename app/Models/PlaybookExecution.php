<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PlaybookExecution extends Model
{
    use TenantScoped;

    protected $fillable = ['playbook_id', 'executable_type', 'executable_id', 'assigned_to', 'started_by', 'status', 'score', 'started_at', 'completed_at', 'cancelled_at', 'metadata'];

    protected function casts(): array
    {
        return ['score' => 'decimal:6', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'metadata' => 'array'];
    }

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    public function executable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PlaybookAnswer::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(PlaybookActionLog::class);
    }
}
