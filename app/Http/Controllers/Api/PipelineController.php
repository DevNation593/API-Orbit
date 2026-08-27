<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PipelineRequest;
use App\Models\Pipeline;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

class PipelineController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly DatabaseManager $database) {}

    public function index(PipelineRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Pipeline::class);

        return ApiResponse::success(Pipeline::query()->with('stages')->where('active', true)->orderBy('name')->get());
    }

    public function store(PipelineRequest $request): JsonResponse
    {
        $this->authorize('create', Pipeline::class);
        $data = $request->validated();
        $stages = $data['stages'] ?? [];
        unset($data['stages']);
        $pipeline = $this->database->transaction(function () use ($data, $stages): Pipeline {
            if (($data['is_default'] ?? false) === true) {
                Pipeline::query()->update(['is_default' => false]);
            }
            $pipeline = Pipeline::create($data);
            foreach ($stages as $stage) {
                unset($stage['id']);
                $pipeline->stages()->create($stage);
            }
            $this->audit->record('create', $pipeline, newValues: $pipeline->getAttributes());

            return $pipeline;
        });

        return ApiResponse::success($pipeline->load('stages'), [], 201);
    }

    public function show(int $id): JsonResponse
    {
        $pipeline = Pipeline::query()->with('stages')->findOrFail($id);
        $this->authorize('view', $pipeline);

        return ApiResponse::success($pipeline);
    }

    public function update(PipelineRequest $request, int $id): JsonResponse
    {
        $pipeline = Pipeline::findOrFail($id);
        $this->authorize('update', $pipeline);
        $data = $request->validated();
        $stages = $data['stages'] ?? null;
        unset($data['stages']);
        $old = $pipeline->getAttributes();
        $this->database->transaction(function () use ($pipeline, $data, $stages): void {
            if (($data['is_default'] ?? false) === true) {
                Pipeline::query()->where('id', '!=', $pipeline->id)->update(['is_default' => false]);
            }
            $pipeline->update($data);
            if ($stages !== null) {
                foreach ($stages as $stage) {
                    $stageId = $stage['id'] ?? null;
                    unset($stage['id']);
                    if ($stageId === null) {
                        $pipeline->stages()->create($stage);

                        continue;
                    }

                    $pipelineStage = $pipeline->stages()->whereKey($stageId)->first();
                    abort_if($pipelineStage === null, 422, 'The stage does not belong to the pipeline.');
                    $pipelineStage->update($stage);
                }
            }
        });
        $this->audit->record('update', $pipeline, oldValues: $old, newValues: $pipeline->getAttributes());

        return ApiResponse::success($pipeline->fresh()->load('stages'));
    }

    public function destroy(int $id): JsonResponse
    {
        $pipeline = Pipeline::findOrFail($id);
        $this->authorize('delete', $pipeline);
        $old = $pipeline->getAttributes();
        $pipeline->delete();
        $this->audit->record('delete', $pipeline, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }
}
