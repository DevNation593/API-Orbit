<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookQuestion extends Model
{
    use TenantScoped;

    protected $fillable = ['playbook_section_id', 'key', 'prompt', 'help_text', 'type', 'position', 'required', 'options', 'validation', 'score_config', 'actions'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'required' => 'boolean', 'options' => 'array', 'validation' => 'array', 'score_config' => 'array', 'actions' => 'array'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(PlaybookSection::class, 'playbook_section_id');
    }
}
