<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ImportBatch;
use App\Models\Lead;
use App\Models\Organization;
use App\Notifications\ImportCompletedNotification;
use App\Services\CustomFieldService;
use App\Services\TabularFileParser;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public readonly int $tenantId, public readonly int $batchId) {}

    public function handle(TabularFileParser $parser, CustomFieldService $customFields, DatabaseManager $database): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->tenantId);
        $batch = null;
        $processed = $failed = 0;
        $errors = [];

        try {
            $batch = ImportBatch::query()->findOrFail($this->batchId);
            $batch->update(['status' => 'processing']);
            $model = match ($batch->entity_type) {
                'contacts' => Contact::class,
                'organizations' => Organization::class,
                'leads' => Lead::class,
                default => throw new \InvalidArgumentException('Unsupported import entity.'),
            };
            $extension = pathinfo($batch->original_filename, PATHINFO_EXTENSION);
            foreach ($parser->rows($batch->disk, $batch->path, $extension) as $line => $row) {
                try {
                    $mapped = $this->mapRow($row, $batch->mapping ?? []);
                    $custom = $mapped['custom_fields'] ?? [];
                    unset($mapped['custom_fields']);
                    $mapped['custom_fields'] = $customFields->validateAndNormalise($batch->entity_type, $custom);
                    $database->transaction(fn () => $model::create($mapped));
                    $processed++;
                } catch (\Throwable $exception) {
                    $failed++;
                    if (count($errors) < 100) {
                        $errors[] = ['row' => $line + 2, 'message' => $exception->getMessage()];
                    }
                }
            }
            $batch->update(['status' => 'completed', 'summary' => compact('processed', 'failed', 'errors')]);
            $batch->user?->notify(new ImportCompletedNotification($batch->fresh()));
        } catch (\Throwable $exception) {
            $batch?->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'summary' => compact('processed', 'failed', 'errors')]);
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    private function mapRow(array $row, array $mapping): array
    {
        $mapped = [];
        foreach ($mapping !== [] ? $mapping : array_combine(array_keys($row), array_keys($row)) as $column => $target) {
            if ($target === null || $target === '') {
                continue;
            }
            $value = $row[$column] ?? null;
            if (str_starts_with((string) $target, 'custom_fields.')) {
                $mapped['custom_fields'][substr((string) $target, 14)] = $value;
            } elseif (in_array($target, ['first_name', 'last_name', 'email', 'phone', 'source', 'name', 'legal_name', 'website', 'status', 'score'], true)) {
                $mapped[$target] = $value;
            }
        }

        return $mapped;
    }
}
