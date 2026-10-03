<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedView extends Model
{
    use TenantScoped;

    protected $fillable = [
        'user_id', 'shared_with_role_id', 'entity_type', 'name', 'visibility',
        'sort_field', 'sort_direction', 'columns', 'is_default',
    ];

    protected function casts(): array
    {
        return ['columns' => 'array', 'is_default' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sharedWithRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'shared_with_role_id');
    }

    public function filters(): HasMany
    {
        return $this->hasMany(SavedViewFilter::class)->orderBy('position')->orderBy('id');
    }
}
