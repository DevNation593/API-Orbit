<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaRule extends Model
{
    use TenantScoped;

    protected $fillable = ['policy_id', 'priority', 'first_response_minutes', 'resolution_minutes'];

    protected function casts(): array
    {
        return ['first_response_minutes' => 'integer', 'resolution_minutes' => 'integer'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'policy_id');
    }
}
