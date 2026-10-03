<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, HasTags, SoftDeletes, TenantScoped;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'source', 'capture_origin', 'medium', 'campaign',
        'content', 'term', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'landing_page', 'referrer', 'first_touch', 'last_touch', 'owner_id', 'territory_id', 'contact_id',
        'organization_id', 'status', 'score', 'score_classification', 'routed_at', 'scored_at',
        'converted_at', 'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'first_touch' => 'array',
            'last_touch' => 'array',
            'routed_at' => 'datetime',
            'scored_at' => 'datetime',
            'converted_at' => 'datetime',
            'custom_fields' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activityable');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'related');
    }

    public function files(): MorphMany
    {
        return $this->morphMany(FileRecord::class, 'related');
    }

    public function captureEvents(): HasMany
    {
        return $this->hasMany(LeadCaptureEvent::class);
    }

    public function routingExecutions(): HasMany
    {
        return $this->hasMany(LeadRoutingExecution::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }
}
