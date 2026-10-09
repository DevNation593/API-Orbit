<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaybookAnswer extends Model
{
    use TenantScoped;

    protected $fillable = ['playbook_execution_id', 'playbook_question_id', 'answered_by', 'value', 'score', 'answered_at'];

    protected function casts(): array
    {
        return ['value' => 'array', 'score' => 'decimal:6', 'answered_at' => 'datetime'];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(PlaybookExecution::class, 'playbook_execution_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PlaybookQuestion::class, 'playbook_question_id');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(PlaybookActionLog::class);
    }
}
