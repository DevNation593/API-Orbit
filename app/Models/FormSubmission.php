<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FormSubmission extends Model
{
    use TenantScoped;

    protected $fillable = [
        'form_id', 'lead_id', 'contact_id', 'public_id', 'status', 'payload', 'attribution',
        'idempotency_key_hash', 'ip_hash', 'user_agent_hash', 'spam_score', 'captcha_verified',
        'error', 'processed_at',
    ];

    protected $hidden = ['idempotency_key_hash', 'ip_hash', 'user_agent_hash'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'attribution' => 'array',
            'spam_score' => 'integer',
            'captcha_verified' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FormSubmission $submission): void {
            $submission->public_id ??= (string) Str::uuid();
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
