<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedViewFilter extends Model
{
    use TenantScoped;

    protected $fillable = ['saved_view_id', 'field', 'operator', 'value', 'position'];

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): mixed => is_string($value) ? json_decode($value, true) : $value,
            set: fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    public function savedView(): BelongsTo
    {
        return $this->belongsTo(SavedView::class);
    }
}
