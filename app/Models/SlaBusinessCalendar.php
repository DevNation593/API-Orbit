<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class SlaBusinessCalendar extends Model
{
    use TenantScoped;

    protected $fillable = ['name', 'timezone', 'mode', 'weekly_schedule', 'holidays', 'is_active'];

    protected function casts(): array
    {
        return ['weekly_schedule' => 'array', 'holidays' => 'array', 'is_active' => 'boolean'];
    }
}
