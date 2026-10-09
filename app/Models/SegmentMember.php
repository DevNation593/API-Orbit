<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class SegmentMember extends Model
{
    use TenantScoped;

    protected $fillable = ['segment_id', 'entity_id'];

    protected function casts(): array
    {
        return [];
    }
}
