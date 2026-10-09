<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookActionLog extends Model
{
    use TenantScoped;

    protected $fillable = ['playbook_execution_id', 'playbook_answer_id', 'action_key', 'type', 'status', 'result', 'error', 'executed_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'executed_at' => 'datetime'];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(PlaybookExecution::class, 'playbook_execution_id');
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(PlaybookAnswer::class, 'playbook_answer_id');
    }
}
