<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRule extends Model
{
    use TenantScoped;

    protected $fillable = ['approval_process_id', 'name', 'priority', 'conditions', 'match_type', 'active'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'conditions' => 'array', 'active' => 'boolean'];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(ApprovalProcess::class, 'approval_process_id');
    }
}
