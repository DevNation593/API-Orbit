<?php

namespace App\Services;

use App\Events\TimelineEventRecorded;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\FileRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

final class TimelineEventRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(Model $subject, string $event, array $metadata = [], ?string $label = null): Activity
    {
        $activity = Activity::create([
            'tenant_id' => (int) $subject->getAttribute('tenant_id'),
            'user_id' => auth()->id(),
            'type' => $event,
            'subject' => $label ?? $this->label($event),
            'activityable_type' => $this->morphType($subject),
            'activityable_id' => (int) $subject->getKey(),
            'occurred_at' => now(),
            'metadata' => $this->sanitize($metadata),
        ]);

        TimelineEventRecorded::dispatch($activity);

        return $activity;
    }

    private function morphType(Model $model): string
    {
        return match ($model::class) {
            Contact::class => 'contact',
            Organization::class => 'organization',
            Lead::class => 'lead',
            Deal::class => 'deal',
            Task::class => 'task',
            FileRecord::class => 'file',
            default => throw new \InvalidArgumentException('Unsupported timeline entity '.$model::class),
        };
    }

    private function label(string $event): string
    {
        return str_replace(['.', '_'], [' · ', ' '], $event);
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $key => $value) {
            if (preg_match('/password|token|secret|credential|authorization|api[_-]?key/i', (string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        return $result;
    }
}
