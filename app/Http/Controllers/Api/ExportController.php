<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportRequest;
use App\Jobs\GenerateExportJob;
use App\Models\ExportBatch;
use App\Services\IdempotencyService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ExportController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly IdempotencyService $idempotency) {}

    public function store(ExportRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('exports.create'), 403, 'You do not have permission to export data.');
        if ($replay = $this->idempotency->replay($request)) {
            return $replay;
        }
        $batch = ExportBatch::create([
            'user_id' => $request->user()->id,
            'entity_type' => $request->validated('entity_type'),
            'status' => 'queued',
            'filters' => ['filter' => $request->validated('filter') ?? [], 'columns' => $request->validated('columns')],
        ]);
        GenerateExportJob::dispatch((int) $batch->tenant_id, (int) $batch->id)->onQueue('exports');
        $this->audit->record('export', $batch, newValues: ['entity_type' => $batch->entity_type]);

        $response = ApiResponse::success($batch, [], 202);
        $this->idempotency->remember($request, $response);

        return $response;
    }

    public function show(int $id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('exports.create'), 403, 'You do not have permission to view exports.');
        $batch = ExportBatch::findOrFail($id);
        $data = $batch->toArray();
        if ($batch->status === 'completed' && $batch->path !== null && $batch->disk === 's3') {
            $data['download_url'] = Storage::disk($batch->disk)->temporaryUrl($batch->path, now()->addMinutes(10));
        }

        return ApiResponse::success($data);
    }
}
