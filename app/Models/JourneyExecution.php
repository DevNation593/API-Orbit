<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class JourneyExecution extends Model
{
    use TenantScoped;

    protected $fillable = ['journey_enrollment_id', 'node_id', 'status', 'output', 'finished_at'];

    protected function casts(): array
    {
        return ['output' => 'array', 'finished_at' => 'datetime'];
    }
}
