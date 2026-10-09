<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journey extends Model
{
    use TenantScoped;

    protected $fillable = ['name', 'description', 'entity_type', 'status', 'published_version', 'created_by'];

    protected function casts(): array
    {
        return ['published_version' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(JourneyVersion::class, 'journey_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(JourneyEnrollment::class, 'journey_id');
    }
}
