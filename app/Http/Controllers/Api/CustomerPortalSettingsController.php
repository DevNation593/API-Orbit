<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerPortalSettingsRequest;
use App\Http\Resources\CustomerPortalResource;
use App\Models\CustomerPortal;
use App\Models\User;
use App\Services\CustomerPortalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CustomerPortalSettingsController extends Controller
{
    public function __construct(private readonly CustomerPortalService $portalService) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CustomerPortal::class);
        $portal = CustomerPortal::query()->firstOrFail();
        Gate::authorize('view', $portal);

        return ApiResponse::success(
            (new CustomerPortalResource($portal))->resolve($request),
        );
    }

    public function upsert(CustomerPortalSettingsRequest $request): JsonResponse
    {
        Gate::authorize('create', CustomerPortal::class);
        /** @var User $actor */
        $actor = $request->user();
        $result = $this->portalService->upsert($request->validated(), $actor);

        return ApiResponse::success(
            (new CustomerPortalResource($result['portal']))->resolve($request),
            [],
            $result['created'] ? 201 : 200,
        );
    }
}
