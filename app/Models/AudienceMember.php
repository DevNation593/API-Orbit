<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class AudienceMember extends Model
{
    use TenantScoped;

    protected $fillable = ['audience_id', 'entity_id'];

    protected function casts(): array
    {
        return [];
    }
}
