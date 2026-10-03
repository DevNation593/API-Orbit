<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use TenantScoped;

    protected $fillable = ['document_type', 'prefix', 'next_number', 'padding'];

    protected function casts(): array
    {
        return ['next_number' => 'integer', 'padding' => 'integer'];
    }
}
