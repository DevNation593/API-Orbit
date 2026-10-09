<?php

namespace App\Services;

use App\Events\QuoteAccepted;
use App\Models\ApprovalRequest;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\DocumentSequence;
use App\Models\Quote;
use App\Models\QuoteActivity;
use App\Models\QuoteApproval;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QuoteService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly QuoteCalculationService $calculator,
        private readonly ApprovalEngine $approvals,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): Quote
    {
        return $this->database->transaction(function () use ($data, $userId): Quote {
            $this->hydrateFromDeal($data);
            $calculation = $this->calculator->calculate($data);
            $quote = Quote::create([
                ...Arr::except($data, ['items', 'discount_codes']),
                'public_id' => (string) Str::uuid(), 'number' => $this->nextNumber(), 'version' => 1,
                'owner_id' => $data['owner_id'] ?? $userId, 'status' => 'draft', ...$calculation['totals'],
                'metadata' => array_replace($data['metadata'] ?? [], ['configuration_warnings' => $calculation['warnings']]),
            ]);
            $this->persistCalculation($quote, $calculation);
            $this->activity($quote, 'quote.created', ['total' => $quote->grand_total]);
            $this->audit->record('quote_created', $quote, newValues: $this->auditValues($quote));

            return $quote->fresh($this->relations());
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Quote $quote, array $data): Quote
    {
        return $this->database->transaction(function () use ($quote, $data): Quote {
            $model = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($model->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft quotes can be edited. Create a revision instead.']);
            }
            $merged = array_replace($this->calculationInput($model), $data);
            $this->hydrateFromDeal($merged);
            $calculation = $this->calculator->calculate($merged);
            $old = $this->auditValues($model);
            $model->fill([
                ...Arr::except($merged, ['items', 'discount_codes', 'number', 'version', 'public_id', 'status']),
                ...$calculation['totals'], 'pdf_file_id' => null,
                'metadata' => array_replace($merged['metadata'] ?? [], ['configuration_warnings' => $calculation['warnings']]),
            ])->save();
            $this->persistCalculation($model, $calculation);
            $this->activity($model, 'quote.updated', ['changed_fields' => array_keys($data)]);
            $this->audit->record('quote_updated', $model, oldValues: $old, newValues: $this->auditValues($model));

            return $model->fresh($this->relations());
        });
    }

    public function duplicate(Quote $quote, int $userId): Quote
    {
        return $this->create($this->copyInput($quote), $userId);
    }

    public function revise(Quote $quote, int $userId): Quote
    {
        return $this->database->transaction(function () use ($quote, $userId): Quote {
            $versions = Quote::query()->where('number', $quote->number)->orderBy('id')->lockForUpdate()->get();
            $source = $versions->firstWhere('id', $quote->id) ?? throw ValidationException::withMessages(['quote' => 'The quote revision no longer exists.']);
            if (! in_array($source->status, ['approved', 'sent', 'viewed', 'rejected', 'expired', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'Only approved or terminal non-accepted quotes can be revised.']);
            }
            $input = $this->copyInput($source);
            $calculation = $this->calculator->calculate($input);
            $rootId = $source->revision_of_id ?? $source->id;
            $version = (int) $versions->max('version') + 1;
            $revision = Quote::create([
                ...Arr::except($input, ['items', 'discount_codes']),
                'public_id' => (string) Str::uuid(), 'number' => $source->number, 'version' => $version,
                'revision_of_id' => $rootId, 'owner_id' => $input['owner_id'] ?? $userId,
                'status' => 'draft', ...$calculation['totals'],
                'metadata' => array_replace($input['metadata'] ?? [], ['configuration_warnings' => $calculation['warnings']]),
            ]);
            $this->persistCalculation($revision, $calculation);
            $this->activity($revision, 'quote.revised', ['previous_quote_id' => (int) $source->id, 'version' => $version]);
            $this->audit->record('quote_revised', $revision, oldValues: ['quote_id' => $source->id], newValues: $this->auditValues($revision));

            return $revision->fresh($this->relations());
        });
    }

    public function submit(Quote $quote, int $userId): Quote
    {
        $expired = false;
        $model = $this->database->transaction(function () use ($quote, $userId, &$expired): Quote {
            $model = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($model->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft quotes can be submitted.']);
            }
            if ($this->isExpired($model)) {
                $this->expire($model);
                $expired = true;

                return $model;
            }
            $approval = $this->approvals->submit($model, $userId, ['grand_total' => (string) $model->grand_total]);
            if ($approval === null) {
                $model->update(['status' => 'approved', 'issued_at' => now()]);
                $this->activity($model, 'quote.approved', ['automatic' => true]);
                $this->audit->record('quote_approved', $model, newValues: $this->auditValues($model));
            }

            return $model;
        });
        if ($expired) {
            throw ValidationException::withMessages(['valid_until' => 'An expired quote cannot be submitted.']);
        }

        return $model->fresh($this->relations());
    }

    public function cancel(Quote $quote, ?string $reason = null): Quote
    {
        return $this->database->transaction(function () use ($quote, $reason): Quote {
            $approval = ApprovalRequest::query()->where('approvable_type', 'quote')->where('approvable_id', $quote->id)
                ->where('status', 'pending')->lockForUpdate()->first();
            $model = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if (in_array($model->status, ['accepted', 'cancelled', 'expired'], true)) {
                throw ValidationException::withMessages(['status' => 'This quote can no longer be cancelled.']);
            }
            $old = $this->auditValues($model);
            if ($approval !== null) {
                $approval->update(['status' => 'cancelled', 'decided_at' => now(), 'due_at' => null]);
                QuoteApproval::query()->where('approval_request_id', $approval->id)
                    ->update(['status' => 'cancelled', 'decided_at' => now()]);
                $this->audit->record('approval_cancelled', $approval, newValues: ['cancelled_by' => auth()->id(), 'source' => 'quote_cancel']);
            }
            $model->update([
                'status' => 'cancelled', 'cancelled_at' => now(),
                'acceptance_token' => null, 'acceptance_token_hash' => null, 'acceptance_idempotency_key_hash' => null,
                'metadata' => array_replace($model->metadata ?? [], ['cancellation_reason' => $reason]),
            ]);
            $this->activity($model, 'quote.cancelled', ['reason' => $reason]);
            $this->audit->record('quote_cancelled', $model, oldValues: $old, newValues: $this->auditValues($model));

            return $model->fresh($this->relations());
        });
    }

    public function delete(Quote $quote): void
    {
        $this->database->transaction(function () use ($quote): void {
            $model = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if (! in_array($model->status, ['draft', 'cancelled', 'expired', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'Only draft or terminal non-accepted quotes can be deleted.']);
            }
            $old = $this->auditValues($model);
            $this->activity($model, 'quote.deleted');
            $model->delete();
            $this->audit->record('quote_deleted', $model, oldValues: $old);
        });
    }

    public function markViewed(Quote $quote): Quote
    {
        return $this->database->transaction(function () use ($quote): Quote {
            $model = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($model->status === 'sent') {
                $model->update(['status' => 'viewed', 'viewed_at' => now()]);
                $this->activity($model, 'quote.viewed');
            }

            return $model->fresh($this->relations());
        });
    }

    /** @return array{quote: Quote, replayed: bool} */
    public function decide(Quote $quote, string $decision, array $data, ?string $ip = null): array
    {
        $key = (string) ($data['idempotency_key'] ?? Str::uuid());
        $keyHash = hash('sha256', $decision.':'.$key);
        $replayed = false;
        $expired = false;
        $model = $this->database->transaction(function () use ($quote, $decision, $data, $ip, $keyHash, &$replayed, &$expired): Quote {
            $model = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if (in_array($model->status, ['accepted', 'rejected'], true)) {
                if (hash_equals((string) $model->acceptance_idempotency_key_hash, $keyHash)) {
                    $replayed = true;

                    return $model;
                }
                throw ValidationException::withMessages(['status' => 'This quote already has a final customer decision.']);
            }
            if (! in_array($model->status, ['sent', 'viewed'], true)) {
                throw ValidationException::withMessages(['status' => 'Only sent or viewed quotes can receive a customer decision.']);
            }
            if ($this->isExpired($model)) {
                $this->expire($model);
                $expired = true;

                return $model;
            }
            $old = $this->auditValues($model);
            $accepted = $decision === 'accept';
            if ($accepted) {
                $this->consumeDiscounts($model);
            }
            $model->update([
                'status' => $accepted ? 'accepted' : 'rejected',
                'accepted_at' => $accepted ? now() : null, 'rejected_at' => $accepted ? null : now(),
                'accepted_by_name' => $data['name'] ?? null, 'accepted_by_email' => $data['email'] ?? null,
                'accepted_from_ip' => $ip, 'acceptance_idempotency_key_hash' => $keyHash,
                'metadata' => array_replace($model->metadata ?? [], ['customer_comment' => $data['comment'] ?? null]),
            ]);
            $this->activity($model, $accepted ? 'quote.accepted' : 'quote.rejected', ['comment' => $data['comment'] ?? null]);
            $this->audit->record($accepted ? 'quote_accepted' : 'quote_rejected', $model, oldValues: $old, newValues: $this->auditValues($model));

            return $model;
        });
        if ($expired) {
            throw ValidationException::withMessages(['valid_until' => 'This quote has expired.']);
        }
        if (! $replayed && $decision === 'accept') {
            QuoteAccepted::dispatch($model);
        }

        return ['quote' => $model->fresh($this->relations()), 'replayed' => $replayed];
    }

    public function assertPublicToken(Quote $quote, string $token): void
    {
        if ($token === '' || $quote->acceptance_token_hash === null || ! hash_equals((string) $quote->acceptance_token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['token' => 'The quote access token is invalid.']);
        }
    }

    /** @param array{items: array<int, array<string, mixed>>, discounts: array<int, array<string, mixed>>, totals: array<string, string>, warnings: array<int, string>} $calculation */
    private function persistCalculation(Quote $quote, array $calculation): void
    {
        $quote->taxes()->delete();
        $quote->discounts()->delete();
        $quote->items()->delete();
        foreach ($calculation['items'] as $line) {
            $taxes = $line['taxes'];
            $discounts = $line['discounts'];
            $metadata = array_replace($line['metadata'] ?? [], ['quote_discount_share' => $line['quote_discount_share']]);
            $item = $quote->items()->create([
                ...Arr::only($line, [
                    'position', 'product_id', 'product_variant_id', 'item_type', 'sku', 'name', 'description',
                    'unit_of_measure', 'quantity', 'unit_price', 'subtotal', 'discount_total', 'tax_total', 'total', 'taxable',
                ]),
                'metadata' => $metadata,
            ]);
            foreach ($discounts as $discount) {
                $item->discounts()->create(['quote_id' => $quote->id, ...$discount]);
            }
            foreach ($taxes as $tax) {
                $item->taxes()->create(['quote_id' => $quote->id, ...$tax]);
            }
        }
        foreach ($calculation['discounts'] as $discount) {
            $quote->discounts()->create($discount);
        }
        $quote->update($calculation['totals']);
    }

    private function nextNumber(): string
    {
        $sequence = DocumentSequence::query()->firstOrCreate(
            ['document_type' => 'quote'],
            ['prefix' => 'Q', 'next_number' => 1, 'padding' => 6],
        );
        $sequence = DocumentSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
        $number = $sequence->prefix.'-'.str_pad((string) $sequence->next_number, $sequence->padding, '0', STR_PAD_LEFT);
        $sequence->increment('next_number');

        return $number;
    }

    /** @param array<string, mixed> $data */
    private function hydrateFromDeal(array &$data): void
    {
        if (blank($data['deal_id'] ?? null)) {
            return;
        }
        $deal = Deal::query()->findOrFail($data['deal_id']);
        $data['contact_id'] ??= $deal->contact_id;
        $data['organization_id'] ??= $deal->organization_id;
        $data['owner_id'] ??= $deal->owner_id;
    }

    /** @return array<string, mixed> */
    private function calculationInput(Quote $quote): array
    {
        return $this->copyInput($quote);
    }

    /** @return array<string, mixed> */
    private function copyInput(Quote $quote): array
    {
        $quote->loadMissing(['items.taxes', 'items.discounts', 'discounts']);

        return [
            'deal_id' => $quote->deal_id, 'contact_id' => $quote->contact_id, 'organization_id' => $quote->organization_id,
            'owner_id' => $quote->owner_id, 'currency_id' => $quote->currency_id, 'price_list_id' => $quote->price_list_id,
            'title' => $quote->title, 'valid_until' => $quote->valid_until?->format('Y-m-d'), 'notes' => $quote->notes,
            'terms' => $quote->terms, 'billing_address' => $quote->billing_address, 'shipping_address' => $quote->shipping_address,
            'metadata' => Arr::except($quote->metadata ?? [], ['configuration_warnings', 'customer_comment']),
            'discount_codes' => $quote->discounts->whereNotNull('discount_id')->pluck('code')->filter()->values()->all(),
            'items' => $quote->items->map(fn ($item): array => [
                'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id,
                'name' => $item->name, 'description' => $item->description, 'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price, 'taxable' => (bool) $item->taxable,
                'tax_ids' => $item->taxes->pluck('tax_id')->filter()->map(fn ($id): int => (int) $id)->all(),
                'discount_ids' => $item->discounts->pluck('discount_id')->filter()->map(fn ($id): int => (int) $id)->all(),
                'metadata' => Arr::except($item->metadata ?? [], ['price_source', 'pricing_rules', 'quote_discount_share']),
            ])->all(),
        ];
    }

    private function consumeDiscounts(Quote $quote): void
    {
        $ids = $quote->allDiscounts()->whereNotNull('discount_id')->pluck('discount_id')->filter()->unique();
        foreach ($ids as $id) {
            $discount = Discount::query()->whereKey($id)->lockForUpdate()->first();
            if ($discount === null) {
                continue;
            }
            if ($discount->usage_limit !== null && $discount->usage_count >= $discount->usage_limit) {
                throw ValidationException::withMessages(['discount' => "Discount {$discount->code} has reached its usage limit."]);
            }
            $discount->increment('usage_count');
        }
    }

    /** @param array<string, mixed> $metadata */
    public function activity(Quote $quote, string $type, array $metadata = []): QuoteActivity
    {
        return $quote->activities()->create(['user_id' => auth()->id(), 'type' => $type, 'metadata' => $metadata, 'occurred_at' => now()]);
    }

    private function expire(Quote $quote): void
    {
        $old = $this->auditValues($quote);
        $quote->update(['status' => 'expired']);
        $this->activity($quote, 'quote.expired');
        $this->audit->record('quote_expired', $quote, oldValues: $old, newValues: $this->auditValues($quote));
    }

    private function isExpired(Quote $quote): bool
    {
        return $quote->valid_until !== null && $quote->valid_until->endOfDay()->isPast();
    }

    /** @return array<int, string> */
    public function relations(): array
    {
        return [
            'deal:id,name,status', 'contact:id,first_name,last_name,email,phone', 'organization:id,name,email,phone',
            'owner:id,name,email', 'currency:id,code,name,symbol,decimal_places', 'priceList:id,name',
            'pdfFile:id,filename,mime_type,size', 'items.product:id,sku,name', 'items.variant:id,sku,name',
            'items.taxes', 'items.discounts', 'discounts', 'approvals.request.process:id,name',
        ];
    }

    /** @return array<string, mixed> */
    private function auditValues(Quote $quote): array
    {
        return [
            'number' => $quote->number, 'version' => $quote->version, 'status' => $quote->status,
            'deal_id' => $quote->deal_id, 'contact_id' => $quote->contact_id, 'organization_id' => $quote->organization_id,
            'currency_id' => $quote->currency_id, 'subtotal' => $quote->subtotal,
            'discount_total' => $quote->discount_total, 'tax_total' => $quote->tax_total,
            'grand_total' => $quote->grand_total,
        ];
    }
}
