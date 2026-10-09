<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    use TenantScoped;

    protected $fillable = [
        'form_id', 'field_definition_id', 'field_key', 'label', 'type', 'mapping_target',
        'placeholder', 'help_text', 'options', 'validation_rules', 'default_value', 'settings',
        'required', 'position', 'active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'validation_rules' => 'array',
            'default_value' => 'array',
            'settings' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function fieldDefinition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class);
    }
}
