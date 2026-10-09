<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
use App\Models\Concerns\TenantScoped;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasTags, SoftDeletes, TenantScoped;

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'owner_id', 'territory_id', 'status', 'custom_fields'];

    protected $hidden = [
        'email_normalized', 'phone_normalized', 'name_normalized',
        'identification_normalized', 'tax_id_normalized',
    ];

    protected function casts(): array
    {
        return ['custom_fields' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('tenant_id')->withTimestamps();
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activityable');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'related');
    }

    public function files(): MorphMany
    {
        return $this->morphMany(FileRecord::class, 'related');
    }
}
