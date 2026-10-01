<?php

namespace App\Services;

use App\Models\Sequence;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final class SequenceDefinitionService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): Sequence
    {
        $data['created_by'] = $userId;

        return $this->persist(new Sequence, $data);
    }

    /** @param array<string, mixed> $data */
    public function update(Sequence $sequence, array $data): Sequence
    {
        return $this->persist($sequence, $data);
    }

    /** @param array<string, mixed> $data */
    private function persist(Sequence $sequence, array $data): Sequence
    {
        return $this->database->transaction(function () use ($sequence, $data): Sequence {
            $steps = $data['steps'] ?? null;
            unset($data['steps']);
            $exists = $sequence->exists;
            if ($exists && is_array($steps) && $sequence->enrollments()
                ->whereIn('status', ['active', 'paused'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'steps' => 'Sequence steps cannot change while active or paused enrollments exist.',
                ]);
            }
            $old = $exists ? $sequence->getAttributes() : null;
            $data['stop_conditions'] ??= $exists ? $sequence->stop_conditions : ['email_reply', 'whatsapp_reply', 'meeting_booked'];
            $sequence->fill($data)->save();
            if (is_array($steps)) {
                $sequence->steps()->delete();
                foreach (array_values($steps) as $position => $step) {
                    $step['position'] = $step['position'] ?? $position;
                    $sequence->steps()->create($step);
                }
            }
            if ($sequence->status === 'active' && ! $sequence->steps()->where('active', true)->exists()) {
                throw ValidationException::withMessages(['steps' => 'An active sequence requires at least one active step.']);
            }
            $this->audit->record($exists ? 'update' : 'create', $sequence, oldValues: $old, newValues: [
                'name' => $sequence->name,
                'status' => $sequence->status,
                'stop_conditions' => $sequence->stop_conditions,
            ]);

            return $sequence->fresh()->load(['steps', 'creator:id,name']);
        });
    }
}
