<?php

namespace App\Models;

use App\Support\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot(['role_id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    public function hasPermission(string $permission, ?int $tenantId = null): bool
    {
        if ((bool) $this->getAttribute('is_platform_admin')) {
            return true;
        }

        $tenantId ??= app(TenantContext::class)->id();
        if ($tenantId === null) {
            return false;
        }

        $membership = $this->memberships()
            ->with('role.permissions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        return (bool) $membership?->role?->permissions?->contains('key', $permission);
    }
}
