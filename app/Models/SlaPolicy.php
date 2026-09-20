<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaPolicy extends Model
{
    use TenantScoped;

    protected $fillable = ['name', 'description', 'calendar_id', 'pause_on_waiting_customer', 'is_active'];

    protected function casts(): array
    {
        return ['pause_on_waiting_customer' => 'boolean', 'is_active' => 'boolean'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(SlaRule::class, 'policy_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(SlaBusinessCalendar::class, 'calendar_id');
    }
}
