<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Form extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'created_by', 'public_id', 'name', 'title', 'description', 'status', 'settings',
        'success_message', 'redirect_url', 'submissions_count', 'published_at', 'active_from', 'active_until',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'submissions_count' => 'integer',
            'published_at' => 'datetime',
            'active_from' => 'datetime',
            'active_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Form $form): void {
            $form->public_id ??= (string) Str::uuid();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('position')->orderBy('id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(fn (Builder $builder) => $builder->whereNull('active_from')->orWhere('active_from', '<=', now()))
            ->where(fn (Builder $builder) => $builder->whereNull('active_until')->orWhere('active_until', '>', now()));
    }
}
