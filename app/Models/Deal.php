<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory, HasTags, SoftDeletes, TenantScoped;

    protected $fillable = [
        'pipeline_id', 'stage_id', 'owner_id', 'sales_team_id', 'branch_id', 'territory_id',
        'contact_id', 'organization_id', 'name', 'value', 'currency', 'status', 'forecast_category',
        'expected_close_date', 'closed_at', 'custom_fields',
    ];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'expected_close_date' => 'date', 'closed_at' => 'datetime', 'custom_fields' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
}
