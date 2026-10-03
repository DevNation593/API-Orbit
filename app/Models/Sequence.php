<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sequence extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'created_by', 'name', 'description', 'status', 'stop_conditions', 'settings', 'enrollments_count',
    ];

    protected function casts(): array
    {
        return [
            'stop_conditions' => 'array',
            'settings' => 'array',
            'enrollments_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('position')->orderBy('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }
}
