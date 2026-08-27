<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\ExportBatch;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Notifications\ExportReadyNotification;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly int $tenantId, public readonly int $batchId) {}

    public function handle(): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        $batch = null;
        $temporary = null;
        $handle = null;
        $count = 0;

        try {
            $batch = ExportBatch::query()->findOrFail($this->batchId);
            $batch->update(['status' => 'processing']);
            $models = ['contacts' => Contact::class, 'organizations' => Organization::class, 'leads' => Lead::class, 'deals' => Deal::class, 'tasks' => Task::class];
            $model = $models[$batch->entity_type] ?? throw new \InvalidArgumentException('Unsupported export entity.');
            $columns = $batch->filters['columns'] ?? null;
            $columns ??= match ($batch->entity_type) {
                'contacts' => ['id', 'first_name', 'last_name', 'email', 'phone', 'status'],
                'organizations' => ['id', 'name', 'email', 'phone', 'website'],
                'leads' => ['id', 'first_name', 'last_name', 'email', 'source', 'status', 'score'],
                'deals' => ['id', 'name', 'value', 'currency', 'status', 'expected_close_date'],
                'tasks' => ['id', 'title', 'status', 'priority', 'due_at'],
            };
            $temporary = tempnam(sys_get_temp_dir(), 'crm-export-');
            $handle = fopen($temporary, 'wb');
            fputcsv($handle, $columns);
            $query = $model::query();
            foreach (($batch->filters['filter'] ?? []) as $field => $definition) {
                if (in_array($field, $columns, true) && is_array($definition) && ($definition['operator'] ?? 'eq') === 'eq') {
                    $query->where($field, $definition['value'] ?? null);
                }
            }
            $query->chunkById(500, function ($rows) use ($handle, $columns, &$count): void {
                foreach ($rows as $row) {
                    fputcsv($handle, array_map(fn ($column) => is_array($row->{$column} ?? null) ? json_encode($row->{$column}) : ($row->{$column} ?? ''), $columns));
                    $count++;
                }
            });
            fclose($handle);
            $path = 'exports/'.$this->tenantId.'/'.$batch->id.'.csv';
            Storage::disk(config('filesystems.default'))->put($path, file_get_contents($temporary));
            $batch->update(['status' => 'completed', 'disk' => config('filesystems.default'), 'path' => $path, 'row_count' => $count]);
            $batch->user?->notify(new ExportReadyNotification($batch->fresh()));
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $batch?->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
