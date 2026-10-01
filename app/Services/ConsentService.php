<?php

namespace App\Services;

use App\Events\ConsentChanged;
use App\Models\ConsentLink;
use App\Models\ConsentRecord;
use App\Models\Tenant;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ConsentService
{
    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    public function __construct(private readonly SegmentEngine $entities, private readonly AuditService $audit) {}

    public function destination(Model $subject, string $channel): ?string
    {
        if ($channel === 'email') {
            $email = mb_strtolower(trim((string) $subject->email));

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        }
        $phone = preg_replace('/[\\s().-]+/', '', (string) $subject->phone);

        return is_string($phone) && preg_match('/^\\+[1-9][0-9]{6,14}$/', $phone) === 1 ? $phone : null;
    }

    public function allowed(Model $subject, string $channel, ?string $expectedDestination = null): bool
    {
        $destination = $this->destination($subject, $channel);
        if ($destination === null || ($expectedDestination !== null && $destination !== $expectedDestination)) {
            return false;
        }

        return $this->status($channel, $destination) === 'opt_in';
    }

    public function status(string $channel, ?string $destination): string
    {
        if ($destination === null) {
            return 'unknown';
        }

        return ConsentRecord::where('channel', $channel)->where('destination', $destination)
            ->orderByDesc('id')->value('status') ?? 'unknown';
    }

    public function preferences(Model $subject): array
    {
        return collect(self::CHANNELS)->mapWithKeys(fn ($channel) => [
            $channel => $this->status($channel, $this->destination($subject, $channel)),
        ])->all();
    }

    public function record(array $data, ?string $ip = null, ?int $actorId = null, ?string $pinnedDestination = null): ConsentRecord
    {
        $data['entity_type'] = $this->entities->normalize((string) ($data['entity_type'] ?? ''));
        $data = Validator::make($data, [
            'entity_type' => ['required', Rule::in(['contacts', 'leads'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'channel' => ['required', Rule::in(self::CHANNELS)],
            'status' => ['required', Rule::in(['opt_in', 'opt_out'])],
            'source' => ['required', 'string', 'max:120'],
            'evidence' => ['required_if:status,opt_in', 'nullable', 'string', 'max:10000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ])->validate();
        $subject = $pinnedDestination === null ? $this->entities->subject($data['entity_type'], (int) $data['entity_id']) : null;
        $destination = $pinnedDestination ?? $this->destination($subject, $data['channel']);
        if ($destination === null) {
            throw ValidationException::withMessages(['entity_id' => 'This recipient has no valid destination for the selected channel.']);
        }
        $data['evidence'] = $data['evidence'] ?? null;
        $data['destination'] = $destination;
        $data['entity_id'] = (int) $data['entity_id'];
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $hash, $ip, $actorId): ConsentRecord {
            // Serialize the consent ledger for this tenant, including duplicate identities sharing an address.
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $existing = ConsentRecord::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'The idempotency key was used with another consent payload.');

                return $existing;
            }
            $record = ConsentRecord::create($data + [
                'payload_hash' => $hash, 'ip' => $ip, 'recorded_by' => $actorId,
                'occurred_at' => now(), 'revoked_at' => $data['status'] === 'opt_out' ? now() : null,
            ]);
            $this->audit->record('consent_'.$record->status, $record, newValues: [
                'entity_type' => $record->entity_type, 'entity_id' => $record->entity_id,
                'channel' => $record->channel, 'source' => $record->source,
            ]);
            ConsentChanged::dispatch($record);

            return $record;
        });
    }

    public function issueLink(string $entityType, int $entityId): array
    {
        $type = $this->entities->normalize($entityType);
        $subject = $this->entities->subject($type, $entityId);
        $token = Str::random(64);
        $link = ConsentLink::create([
            'entity_type' => $type, 'entity_id' => $entityId, 'token_hash' => hash('sha256', $token),
            'destinations' => collect(self::CHANNELS)->mapWithKeys(fn ($channel) => [$channel => $this->destination($subject, $channel)])->all(),
            'expires_at' => now()->addDays(90),
        ]);
        $this->audit->record('consent_link_issued', $link);

        return [
            'id' => (int) $link->id, 'token' => $token, 'expires_at' => $link->expires_at,
            'url' => url('/api/v1/public/preferences/'.$token),
        ];
    }

    public function findLink(string $token): ConsentLink
    {
        return ConsentLink::withoutGlobalScope('tenant')->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
    }
}
