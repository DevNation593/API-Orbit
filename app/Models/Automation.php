<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Automation extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = ['name', 'event_type', 'config', 'version', 'active'];

    protected function casts(): array
    {
        return ['config' => 'array', 'active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
