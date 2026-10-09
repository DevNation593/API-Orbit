<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteDiscount extends Model
{
    use TenantScoped;

    protected $fillable = ['quote_id', 'quote_item_id', 'discount_id', 'discount_rule_id', 'code', 'name', 'type', 'value', 'amount', 'reason'];

    protected function casts(): array
    {
        return ['value' => 'decimal:6', 'amount' => 'decimal:6'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class, 'quote_item_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id');
    }
}
