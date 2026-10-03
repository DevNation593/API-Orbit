<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadCaptureEvent extends Model
{
    use TenantScoped;

    protected $fillable = [
        'lead_id', 'form_submission_id', 'origin', 'idempotency_key_hash', 'attribution', 'payload', 'occurred_at',
    ];

    protected $hidden = ['idempotency_key_hash'];

    protected function casts(): array
    {
        return [
            'attribution' => 'array',
            'payload' => 'encrypted:array',
            'occurred_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function formSubmission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class);
    }
}
