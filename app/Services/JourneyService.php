<?php

namespace App\Services;

use App\Jobs\DeliverConversationMessageJob;
use App\Jobs\ProcessMarketingJob;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\JourneyVersion;
use App\Models\Message;
use App\Models\TenantUser;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

final class JourneyService
{
    public function __construct(private readonly JourneyGraphService $graphs, private readonly SegmentEngine $entities, private readonly AuditService $audit) {}

    public function version(Journey $journey, array $graph): JourneyVersion
    {
        $graph = $this->graphs->validate($graph, $journey->entity_type);

        return DB::transaction(function () use ($journey, $graph): JourneyVersion {
            $journey = Journey::whereKey($journey->id)->lockForUpdate()->firstOrFail();
            abort_if($journey->status === 'archived', 409, 'An archived journey cannot receive versions.');
            $version = $journey->versions()->create(['version' => ((int) $journey->versions()->max('version')) + 1, 'graph' => $graph]);
            $this->audit->record('journey_version_created', $version);

            return $version;
        });
    }

    public function publish(Journey $journey, int $versionId): Journey
    {
        return DB::transaction(function () use ($journey, $versionId): Journey {
            $journey = Journey::whereKey($journey->id)->lockForUpdate()->firstOrFail();
            abort_if($journey->status === 'archived', 409, 'Archived journeys cannot be published.');
            $version = $journey->versions()->findOrFail($versionId);
            $this->graphs->validate($version->graph, $journey->entity_type);
            $version->update(['published_at' => $version->published_at ?? now()]);
            $journey->update(['status' => 'active', 'published_version' => $version->version]);
            $this->audit->record('journey_published', $journey, newValues: ['version' => $version->version]);
            ProcessMarketingJob::dispatch((int) $journey->tenant_id, 'journey_resume', (int) $journey->id)->afterCommit();

            return $journey->fresh(['versions']);
        });
    }

    public function enroll(Journey $journey, array $data): JourneyEnrollment
    {
        $this->entities->subject($journey->entity_type, (int) $data['entity_id']);
        $payload = ['entity_id' => (int) $data['entity_id'], 'sender_user_id' => (int) $data['sender_user_id']];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($journey, $data, $hash): JourneyEnrollment {
            $journey = Journey::whereKey($journey->id)->lockForUpdate()->firstOrFail();
            $existing = $journey->enrollments()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'This enrollment key belongs to a different payload.');

                return $existing;
            }
            abort_unless($journey->status === 'active', 409, 'Only published active journeys can enroll recipients.');
            abort_unless(TenantUser::where('tenant_id', $journey->tenant_id)->where('user_id', $data['sender_user_id'])->where('status', 'active')->exists(), 422, 'Select an active tenant member as sender.');
            abort_if($journey->enrollments()->where('entity_id', $data['entity_id'])->whereIn('status', ['active', 'paused'])->exists(), 409, 'The recipient is already enrolled in this journey.');
            $version = $journey->versions()->where('version', $journey->published_version)->whereNotNull('published_at')->firstOrFail();
            $enrollment = $journey->enrollments()->create([
                'journey_version_id' => $version->id, 'entity_type' => $journey->entity_type,
                'entity_id' => $data['entity_id'], 'sender_user_id' => $data['sender_user_id'],
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                'current_node' => $version->graph['entry'], 'next_run_at' => now(),
            ]);
            $this->audit->record('journey_enrolled', $enrollment);
            ProcessMarketingJob::dispatch((int) $journey->tenant_id, 'journey', (int) $enrollment->id)->afterCommit();

            return $enrollment;
        });
    }

    public function transition(JourneyEnrollment $enrollment, string $action): JourneyEnrollment
    {
        return DB::transaction(function () use ($enrollment, $action): JourneyEnrollment {
            $model = JourneyEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $target = match ($action) {
                'pause' => 'paused', 'resume' => 'active', 'cancel' => 'cancelled'
            };
            if ($model->status === $target) {
                return $model;
            }
            abort_unless(in_array($model->status, $action === 'resume' ? ['paused'] : ['active', 'paused'], true), 409, 'Invalid enrollment transition.');
            $model->update([
                'status' => $target,
                // Pauses preserve the absolute due date; elapsed waits are not restarted.
                'next_run_at' => $action === 'cancel' ? null : $model->next_run_at,
                'stop_reason' => $action === 'cancel' ? 'manual_cancel' : null,
            ]);
            $this->audit->record('journey_enrollment_'.$action, $model);
            if ($action === 'resume') {
                $this->resumeMessages($model);
                ProcessMarketingJob::dispatch((int) $model->tenant_id, 'journey', (int) $model->id)->afterCommit();
            }

            return $model->fresh();
        });
    }

    public function resumeMessages(JourneyEnrollment $enrollment): void
    {
        foreach (Message::where('status', 'queued')
            ->where('metadata->marketing->journey_enrollment_id', $enrollment->id)->pluck('id') as $id) {
            DeliverConversationMessageJob::dispatch((int) $enrollment->tenant_id, (int) $id)->afterCommit();
        }
    }
}
