<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTeamMember extends Model
{
    use TenantScoped;

    protected $fillable = ['sales_team_id', 'user_id', 'role', 'quota_weight', 'active'];

    protected function casts(): array
    {
        return ['quota_weight' => 'decimal:6', 'active' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
