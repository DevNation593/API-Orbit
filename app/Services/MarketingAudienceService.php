<?php

namespace App\Services;

use App\Models\Audience;
use App\Models\Segment;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarketingAudienceService
{
    public function __construct(private readonly SegmentEngine $engine, private readonly AuditService $audit) {}

    public function refreshSegment(Segment $segment): Segment
    {
        return DB::transaction(function () use ($segment): Segment {
            $segment = Segment::whereKey($segment->id)->lockForUpdate()->firstOrFail();
            abort_unless($segment->active, 409, 'The segment is inactive.');
            $query = $this->engine->query($segment->entity_type, $segment->definition, $segment->entity_definition_id);
            $this->snapshot($query, 'segment_members', 'segment_id', (int) $segment->id);
            $segment->update(['refreshed_at' => now()]);
            $this->audit->record('segment_refreshed', $segment);

            return $segment->fresh()->loadCount('members');
        });
    }

    public function query(Audience $audience): Builder
    {
        abort_unless($audience->active, 409, 'The audience is inactive.');
        if ($audience->type === 'dynamic') {
            $segment = $audience->segment;
            abort_unless($segment?->active && $segment->entity_type === $audience->entity_type, 409, 'The audience requires an active matching segment.');

            return $this->engine->query($segment->entity_type, $segment->definition, $segment->entity_definition_id);
        }

        return $this->engine->base($audience->entity_type)
            ->whereIn('id', $audience->members()->select('entity_id'));
    }

    public function refreshAudience(Audience $audience): Audience
    {
        return DB::transaction(function () use ($audience): Audience {
            $audience = Audience::whereKey($audience->id)->lockForUpdate()->firstOrFail();
            if ($audience->type === 'dynamic') {
                $this->snapshot($this->query($audience), 'audience_members', 'audience_id', (int) $audience->id);
            }
            $audience->update(['refreshed_at' => now()]);
            $this->audit->record('audience_refreshed', $audience);

            return $audience->fresh()->loadCount('members');
        });
    }

    public function members(Audience $audience, array $ids, bool $remove = false): Audience
    {
        return DB::transaction(function () use ($audience, $ids, $remove): Audience {
            $audience = Audience::whereKey($audience->id)->lockForUpdate()->firstOrFail();
            abort_unless($audience->type === 'static', 409, 'Dynamic membership is defined by its segment.');
            $found = $this->engine->base($audience->entity_type)->whereIn('id', $ids)->pluck('id')->all();
            if (count($found) !== count($ids)) {
                throw ValidationException::withMessages(['entity_ids' => 'All recipients must exist in this tenant.']);
            }
            if ($remove) {
                $audience->members()->whereIn('entity_id', $ids)->delete();
            } else {
                $now = now();
                DB::table('audience_members')->insertOrIgnore(array_map(fn ($id) => [
                    'tenant_id' => $audience->tenant_id, 'audience_id' => $audience->id,
                    'entity_id' => $id, 'created_at' => $now, 'updated_at' => $now,
                ], $ids));
            }
            $this->audit->record($remove ? 'audience_members_removed' : 'audience_members_added', $audience, newValues: ['entity_ids' => $ids]);

            return $audience->fresh()->loadCount('members');
        });
    }

    private function snapshot(Builder $query, string $table, string $foreignKey, int $id): void
    {
        $tenant = app(TenantContext::class)->requireId();
        DB::table($table)->where('tenant_id', $tenant)->where($foreignKey, $id)->delete();
        $query->select('id')->chunkById(500, function ($records) use ($table, $foreignKey, $id, $tenant): void {
            $now = now();
            DB::table($table)->insert($records->map(fn ($record) => [
                'tenant_id' => $tenant, $foreignKey => $id, 'entity_id' => $record->id,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });
    }
}
