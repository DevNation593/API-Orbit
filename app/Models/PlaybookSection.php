<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaybookSection extends Model
{
    use TenantScoped;

    protected $fillable = ['playbook_id', 'title', 'description', 'position', 'required', 'conditions'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'required' => 'boolean', 'conditions' => 'array'];
    }

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PlaybookQuestion::class)->orderBy('position');
    }
}
