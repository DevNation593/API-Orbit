<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CannedResponse extends Model
{
    use TenantScoped;

    protected $fillable = ['title', 'shortcut', 'body', 'channel', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
