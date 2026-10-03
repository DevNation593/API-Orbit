<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Playbook extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['created_by', 'name', 'entity_type', 'version', 'description', 'active', 'settings'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'active' => 'boolean', 'settings' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PlaybookSection::class)->orderBy('position');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(PlaybookExecution::class);
    }
}
