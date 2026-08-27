<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntityDefinition extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = ['name', 'label', 'settings', 'active'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FieldDefinition::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(EntityRecord::class);
    }
}
