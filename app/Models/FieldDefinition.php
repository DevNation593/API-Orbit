<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldDefinition extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = ['entity_type', 'entity_definition_id', 'name', 'label', 'type', 'required', 'options', 'validation_rules', 'default_value', 'position', 'active'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'options' => 'array', 'validation_rules' => 'array', 'default_value' => 'json', 'active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entityDefinition(): BelongsTo
    {
        return $this->belongsTo(EntityDefinition::class);
    }
}
