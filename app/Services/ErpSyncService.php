<?php

namespace App\Services;

use App\Jobs\SyncErpEntityJob;
use App\Models\ErpSync;
use App\Models\Integration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class ErpSyncService
{
    public function queue(string $entityType, Model $entity): ErpSync
    {
        if (! in_array($entityType, ['product', 'quote'], true)) {
            throw ValidationException::withMessages(['entity_type' => 'This entity cannot be synchronized to ERP.']);
        }
        $integration = Integration::query()->where('provider', 'vantex_erp')->where('status', 'active')->orderBy('id')->first();
        if ($integration === null) {
            throw ValidationException::withMessages(['integration' => 'Configure and activate a Vantex ERP integration first.']);
        }
        $fingerprint = hash('sha256', implode(':', [
            $entityType, $entity->getKey(), $entity->getAttribute('updated_at')?->format('U.u') ?? '0',
            $entity->getAttribute('status') ?? '', $entity->getAttribute('version') ?? '',
        ]));
        $key = "{$entityType}:{$entity->getKey()}:{$fingerprint}";
        $sync = ErpSync::query()->firstOrCreate(
            ['idempotency_key' => $key],
            ['integration_id' => $integration->id, 'entity_type' => $entityType, 'entity_id' => $entity->getKey(), 'operation' => 'upsert', 'status' => 'queued'],
        );
        if ($sync->wasRecentlyCreated || $sync->status === 'failed') {
            if ($sync->status === 'failed') {
                $sync->update(['status' => 'queued', 'error' => null]);
            }
            SyncErpEntityJob::dispatch((int) $sync->tenant_id, (int) $sync->id)->onQueue('integrations');
        }

        return $sync->fresh(['integration:id,provider,name,status', 'logs']);
    }
}
