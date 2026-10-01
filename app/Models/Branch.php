<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['parent_id', 'currency_id', 'manager_id', 'code', 'name', 'timezone', 'active', 'settings'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'settings' => 'array'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(SalesTeam::class);
    }

    public function territories(): HasMany
    {
        return $this->hasMany(Territory::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(BranchMember::class);
    }
}
