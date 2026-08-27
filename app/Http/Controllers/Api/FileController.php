<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileRecord;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantContext;
use App\Support\TenantRelationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantRelationResolver $relations,
    ) {}

    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('files.view'), 403, 'You do not have permission to view files.');

        return ApiResponse::paginated(FileRecord::query()->orderByDesc('created_at')->paginate(ApiResponse::perPage(request('per_page', 25))));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('files.create'), 403, 'You do not have permission to upload files.');
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
            'related_type' => ['nullable', 'string', 'max:120'],
            'related_id' => ['nullable', 'string', 'max:120'],
        ]);
        $relatedType = $this->relations->canonicalMorphType($request->input('related_type'), $request->input('related_id'));
        $uploaded = $request->file('file');
        $disk = config('filesystems.default');
        $path = 'tenants/'.app(TenantContext::class)->requireId().'/files/'.Str::uuid().'.'.$uploaded->getClientOriginalExtension();
        Storage::disk($disk)->putFileAs(dirname($path), $uploaded, basename($path));
        $record = FileRecord::create([
            'disk' => $disk,
            'path' => $path,
            'filename' => $uploaded->getClientOriginalName(),
            'mime_type' => $uploaded->getMimeType() ?: 'application/octet-stream',
            'size' => $uploaded->getSize(),
            'uploaded_by' => $request->user()->id,
            'related_type' => $relatedType,
            'related_id' => $request->input('related_id'),
            'metadata' => ['client_extension' => $uploaded->getClientOriginalExtension()],
        ]);
        $this->audit->record('create', $record, newValues: $record->getAttributes());

        return ApiResponse::success($record, [], 201);
    }

    public function download(int $id): mixed
    {
        abort_unless(request()->user()->hasPermission('files.view'), 403, 'You do not have permission to view files.');
        $record = FileRecord::findOrFail($id);
        $storage = Storage::disk($record->disk);
        if (method_exists($storage, 'temporaryUrl') && $record->disk === 's3') {
            return ApiResponse::success(['url' => $storage->temporaryUrl($record->path, now()->addMinutes(10))]);
        }

        return $storage->download($record->path, $record->filename, ['Content-Type' => $record->mime_type]);
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('files.delete'), 403, 'You do not have permission to delete files.');
        $record = FileRecord::findOrFail($id);
        Storage::disk($record->disk)->delete($record->path);
        $old = $record->getAttributes();
        $record->delete();
        $this->audit->record('delete', $record, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
