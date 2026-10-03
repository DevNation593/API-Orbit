<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    use TenantScoped;

    protected $fillable = [
        'created_by', 'name', 'subject', 'body_html', 'body_text', 'variables', 'active',
    ];

    protected function casts(): array
    {
        return ['variables' => 'array', 'active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
