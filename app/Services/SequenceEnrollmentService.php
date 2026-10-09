<?php

namespace App\Services;

use App\Jobs\ProcessSequenceEnrollmentJob;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\TenantUser;
use App\Support\AuditService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final class SequenceEnrollmentService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function enroll(Sequence $sequence, array $data, int $userId): SequenceEnrollment
    {
        if ($sequence->status !== 'active') {
            throw ValidationException::withMessages(['sequence' => 'Only active sequences accept enrollments.']);
        }
        $sequence->loadMissing('steps');
        $firstStep = $sequence->steps->where('active', true)->sortBy('position')->first();
        if ($firstStep === null) {
            throw ValidationException::withMessages(['sequence' => 'The sequence has no active steps.']);
        }

        $lead = isset($data['lead_id']) ? Lead::with('contact')->findOrFail($data['lead_id']) : null;
        $contact = isset($data['contact_id']) ? Contact::findOrFail($data['contact_id']) : null;
        $senderId = $data['sender_user_id'] ?? $sequence->created_by ?? $lead?->owner_id ?? $contact?->owner_id;
        if ($senderId === null || ! TenantUser::query()->where('tenant_id', $this->context->requireId())
            ->where('user_id', $senderId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['sender_user_id' => 'An active tenant sender is required.']);
        }
        $this->assertReachable($sequence, $lead, $contact);

        $enrollment = $this->database->transaction(function () use ($sequence, $data, $userId, $senderId, $firstStep): SequenceEnrollment {
            $duplicate = SequenceEnrollment::query()->where('sequence_id', $sequence->id)
                ->where('lead_id', $data['lead_id'] ?? null)->where('contact_id', $data['contact_id'] ?? null)
                ->whereIn('status', ['active', 'paused'])->lockForUpdate()->first();
            if ($duplicate !== null) {
                throw ValidationException::withMessages(['target' => 'This record already has an active enrollment in the sequence.']);
            }
            $start = filled($data['start_at'] ?? null) ? CarbonImmutable::parse($data['start_at']) : CarbonImmutable::now();
            if ($start->isPast()) {
                $start = CarbonImmutable::now();
            }
            $enrollment = SequenceEnrollment::create([
                'sequence_id' => $sequence->id,
                'lead_id' => $data['lead_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'enrolled_by' => $userId,
                'sender_user_id' => $senderId,
                'status' => 'active',
                'current_position' => $firstStep->position,
                'next_run_at' => $start->addMinutes($firstStep->delay_minutes),
                'started_at' => now(),
                'metadata' => $data['metadata'] ?? null,
            ]);
            $sequence->increment('enrollments_count');
            $this->audit->record('sequence_enrolled', $enrollment, newValues: [
                'sequence_id' => (int) $sequence->id,
                'lead_id' => $enrollment->lead_id,
                'contact_id' => $enrollment->contact_id,
                'next_run_at' => $enrollment->next_run_at?->toISOString(),
            ]);

            return $enrollment;
        });

        if ($enrollment->next_run_at?->lte(now())) {
            ProcessSequenceEnrollmentJob::dispatch((int) $enrollment->tenant_id, (int) $enrollment->id)->onQueue('sequences');
        }

        return $enrollment->load(['sequence:id,name,status', 'lead:id,first_name,last_name,email,phone', 'contact:id,first_name,last_name,email,phone', 'sender:id,name,email']);
    }

    public function pause(SequenceEnrollment $enrollment, ?string $reason = null): SequenceEnrollment
    {
        if ($enrollment->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'Only active enrollments can be paused.']);
        }
        $enrollment->update(['status' => 'paused', 'stop_reason' => $reason]);
        $this->audit->record('sequence_paused', $enrollment, newValues: ['reason' => $reason]);

        return $enrollment->fresh();
    }

    public function resume(SequenceEnrollment $enrollment): SequenceEnrollment
    {
        if ($enrollment->status !== 'paused') {
            throw ValidationException::withMessages(['status' => 'Only paused enrollments can be resumed.']);
        }
        $enrollment->update(['status' => 'active', 'stop_reason' => null, 'next_run_at' => now()]);
        $this->audit->record('sequence_resumed', $enrollment);
        ProcessSequenceEnrollmentJob::dispatch((int) $enrollment->tenant_id, (int) $enrollment->id)->onQueue('sequences');

        return $enrollment->fresh();
    }

    public function stop(SequenceEnrollment $enrollment, string $reason = 'manual_stop'): SequenceEnrollment
    {
        if (in_array($enrollment->status, ['completed', 'stopped'], true)) {
            return $enrollment;
        }
        $enrollment->update([
            'status' => 'stopped', 'stop_reason' => mb_substr($reason, 0, 190),
            'stopped_at' => now(), 'next_run_at' => null,
        ]);
        $this->audit->record('sequence_stopped', $enrollment, newValues: ['reason' => $reason]);

        return $enrollment->fresh();
    }

    private function assertReachable(Sequence $sequence, ?Lead $lead, ?Contact $contact): void
    {
        $contact ??= $lead?->contact;
        $email = $contact?->email ?? $lead?->email;
        $phone = $contact?->phone ?? $lead?->phone;
        $types = $sequence->steps->where('active', true)->pluck('type')->all();
        if (in_array('email', $types, true) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['target' => 'The sequence target has no valid email address.']);
        }
        if (array_intersect(['whatsapp', 'sms'], $types) !== [] && preg_replace('/\D+/', '', (string) $phone) === '') {
            throw ValidationException::withMessages(['target' => 'The sequence target has no valid phone number.']);
        }
    }
}
