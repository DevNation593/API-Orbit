<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'public_id', 'number', 'version', 'revision_of_id', 'deal_id', 'contact_id', 'organization_id',
        'owner_id', 'currency_id', 'price_list_id', 'pdf_file_id', 'status', 'title', 'valid_until',
        'issued_at', 'sent_at', 'viewed_at', 'accepted_at', 'rejected_at', 'cancelled_at',
        'subtotal', 'discount_total', 'tax_total', 'grand_total', 'notes', 'terms', 'billing_address',
        'shipping_address', 'acceptance_token', 'acceptance_token_hash', 'acceptance_idempotency_key_hash',
        'accepted_by_name', 'accepted_by_email', 'accepted_from_ip', 'erp_document_id', 'metadata',
    ];

    protected $hidden = ['acceptance_token', 'acceptance_token_hash', 'acceptance_idempotency_key_hash'];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'valid_until' => 'date', 'issued_at' => 'datetime', 'sent_at' => 'datetime',
            'viewed_at' => 'datetime', 'accepted_at' => 'datetime', 'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime', 'subtotal' => 'decimal:6', 'discount_total' => 'decimal:6',
            'tax_total' => 'decimal:6', 'grand_total' => 'decimal:6', 'billing_address' => 'array',
            'shipping_address' => 'array', 'acceptance_token' => 'encrypted', 'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revision_of_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(FileRecord::class, 'pdf_file_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position')->orderBy('id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(QuoteTax::class)->orderBy('position');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(QuoteDiscount::class)->whereNull('quote_item_id');
    }

    public function allDiscounts(): HasMany
    {
        return $this->hasMany(QuoteDiscount::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(QuoteApproval::class)->latest('requested_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(QuoteActivity::class)->latest('occurred_at');
    }
}
