<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteTax extends Model
{
    use TenantScoped;

    protected $fillable = ['quote_id', 'quote_item_id', 'tax_id', 'code', 'name', 'calculation', 'rate', 'taxable_amount', 'amount', 'inclusive', 'compound', 'position'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'amount' => 'decimal:6', 'inclusive' => 'boolean', 'compound' => 'boolean', 'position' => 'integer'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class, 'quote_item_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
