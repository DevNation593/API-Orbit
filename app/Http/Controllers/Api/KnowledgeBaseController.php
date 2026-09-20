<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBaseRequest;
use App\Models\KnowledgeBase;
use App\Services\KnowledgeConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KnowledgeConfigurationService $configuration) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', KnowledgeBase::class);

        return ApiResponse::success(KnowledgeBase::query()->firstOrFail());
    }

    public function upsert(KnowledgeBaseRequest $request): JsonResponse
    {
        Gate::authorize('create', KnowledgeBase::class);
        $result = $this->configuration->saveBase($request->validated(), (int) $request->user()->id);

        return ApiResponse::success($result['base'], [], $result['created'] ? 201 : 200);
    }
}
