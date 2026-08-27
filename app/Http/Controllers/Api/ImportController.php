<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportRequest;
use App\Jobs\ProcessImportJob;
use App\Models\ImportBatch;
use App\Services\IdempotencyService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly IdempotencyService $idempotency) {}

    public function store(ImportRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('imports.create'), 403, 'You do not have permission to import data.');
        if ($replay = $this->idempotency->replay($request)) {
            return $replay;
        }
        $file = $request->file('file');
        $disk = config('filesystems.default');
        $path = 'imports/'.app(TenantContext::class)->requireId().'/'.Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'entity_type' => $request->validated('entity_type'),
            'original_filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'status' => 'queued',
            'mapping' => $request->validated('mapping') ?? [],
        ]);
        ProcessImportJob::dispatch((int) $batch->tenant_id, (int) $batch->id)->onQueue('imports');
        $this->audit->record('import', $batch, newValues: ['entity_type' => $batch->entity_type, 'filename' => $batch->original_filename]);

        $response = ApiResponse::success($batch, [], 202);
        $this->idempotency->remember($request, $response);

        return $response;
    }

    public function show(int $id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('imports.create'), 403, 'You do not have permission to view imports.');

        return ApiResponse::success(ImportBatch::findOrFail($id));
    }
}
