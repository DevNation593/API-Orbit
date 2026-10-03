<?php

namespace App\Services;

use App\Models\MeetingType;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;

final class MeetingTypeService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): MeetingType
    {
        $data['created_by'] = $userId;

        return $this->persist(new MeetingType, $data);
    }

    /** @param array<string, mixed> $data */
    public function update(MeetingType $meetingType, array $data): MeetingType
    {
        return $this->persist($meetingType, $data);
    }

    /** @param array<string, mixed> $data */
    private function persist(MeetingType $meetingType, array $data): MeetingType
    {
        return $this->database->transaction(function () use ($meetingType, $data): MeetingType {
            $availability = $data['availability'] ?? null;
            $exclusions = $data['exclusions'] ?? null;
            unset($data['availability'], $data['exclusions']);
            $exists = $meetingType->exists;
            $old = $exists ? $this->auditValues($meetingType) : null;

            if (($data['assignment_strategy'] ?? $meetingType->assignment_strategy) === 'round_robin') {
                $data['host_user_id'] = null;
            }
            $meetingType->fill($data)->save();

            if (is_array($availability)) {
                $meetingType->availabilityRules()->delete();
                foreach ($availability as $rule) {
                    $rule['timezone'] = $rule['timezone'] ?? $meetingType->timezone;
                    $meetingType->availabilityRules()->create($rule);
                }
            }
            if (is_array($exclusions)) {
                $meetingType->exclusions()->delete();
                foreach ($exclusions as $exclusion) {
                    $meetingType->exclusions()->create($exclusion);
                }
            }

            $this->audit->record($exists ? 'update' : 'create', $meetingType, oldValues: $old, newValues: $this->auditValues($meetingType));

            return $meetingType->fresh()->load([
                'host:id,name,email',
                'creator:id,name',
                'availabilityRules.user:id,name,email',
                'exclusions.user:id,name,email',
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function auditValues(MeetingType $meetingType): array
    {
        return [
            'name' => $meetingType->name,
            'host_user_id' => $meetingType->host_user_id,
            'duration_minutes' => $meetingType->duration_minutes,
            'timezone' => $meetingType->timezone,
            'assignment_strategy' => $meetingType->assignment_strategy,
            'location_type' => $meetingType->location_type,
            'active' => $meetingType->active,
        ];
    }
}
