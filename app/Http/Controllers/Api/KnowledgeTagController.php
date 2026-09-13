<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeTagRequest;
use App\Models\KnowledgeTag;
use App\Services\KnowledgeConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KnowledgeTagController extends Controller
{
    public function __construct(private readonly KnowledgeConfigurationService $configuration) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', KnowledgeTag::class);
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = KnowledgeTag::query();
        if (array_key_exists('active', $data)) {
            $query->where('is_active', $data['active']);
        }
        if (filled($data['q'] ?? null)) {
            $search = $this->escapedSearch((string) $data['q']);
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }

        return ApiResponse::paginated(
            $query->orderBy('name')->orderBy('id')
                ->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString(),
        );
    }

    public function store(KnowledgeTagRequest $request): JsonResponse
    {
        Gate::authorize('create', KnowledgeTag::class);
        $tag = $this->configuration->saveTag(
            $request->validated(),
            (int) $request->user()->id,
        );

        return ApiResponse::success($tag, [], 201);
    }

    public function show(int $tag): JsonResponse
    {
        $model = KnowledgeTag::findOrFail($tag);
        Gate::authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(KnowledgeTagRequest $request, int $tag): JsonResponse
    {
        $model = KnowledgeTag::findOrFail($tag);
        Gate::authorize('update', $model);

        return ApiResponse::success($this->configuration->saveTag(
            $request->validated(),
            (int) $request->user()->id,
            $model,
        ));
    }

    public function destroy(int $tag): JsonResponse
    {
        $model = KnowledgeTag::findOrFail($tag);
        Gate::authorize('delete', $model);
        $this->configuration->deleteCatalog($model);

        return ApiResponse::success(['deleted' => true]);
    }

    private function escapedSearch(string $value): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            mb_strtolower(trim($value)),
        );
    }
}
