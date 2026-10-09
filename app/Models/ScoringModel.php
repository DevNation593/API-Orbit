<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoringModel extends Model
{
    use TenantScoped;

    protected $fillable = [
        'name', 'description', 'minimum_score', 'maximum_score', 'warm_threshold',
        'hot_threshold', 'is_default', 'active', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'minimum_score' => 'integer',
            'maximum_score' => 'integer',
            'warm_threshold' => 'integer',
            'hot_threshold' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ScoringRule::class)->orderBy('priority')->orderBy('id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }
}
