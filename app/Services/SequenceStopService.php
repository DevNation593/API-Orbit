<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\SequenceEnrollment;

final class SequenceStopService
{
    public function stopForLead(Lead $lead, string $condition): int
    {
        return $this->stop(SequenceEnrollment::query()->where('lead_id', $lead->id)
            ->whereIn('status', ['active', 'paused'])->get(), $condition);
    }

    public function stopForContact(Contact $contact, string $condition): int
    {
        $enrollments = SequenceEnrollment::query()->whereIn('status', ['active', 'paused'])->where(function ($query) use ($contact): void {
            $query->where('contact_id', $contact->id)
                ->orWhereHas('lead', fn ($lead) => $lead->where('contact_id', $contact->id));
        })->get();

        return $this->stop($enrollments, $condition);
    }

    /** @param iterable<int, SequenceEnrollment> $enrollments */
    private function stop(iterable $enrollments, string $condition): int
    {
        $count = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment->loadMissing('sequence');
            if (! in_array($condition, $enrollment->sequence->stop_conditions ?? [], true)) {
                continue;
            }
            $updated = SequenceEnrollment::query()->whereKey($enrollment->id)
                ->whereIn('status', ['active', 'paused'])->update([
                    'status' => 'stopped',
                    'stop_reason' => $condition,
                    'stopped_at' => now(),
                    'next_run_at' => null,
                ]);
            $count += $updated;
        }

        return $count;
    }
}
