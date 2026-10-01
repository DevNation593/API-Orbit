<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Territory extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['parent_id', 'branch_id', 'manager_id', 'code', 'name', 'type', 'description', 'position', 'active', 'settings'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'active' => 'boolean', 'settings' => 'array'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TerritoryMember::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(TerritoryRule::class)->orderBy('priority')->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TerritoryAssignment::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
