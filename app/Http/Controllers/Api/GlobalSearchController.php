<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GlobalSearchRequest;
use App\Services\GlobalSearchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __construct(private readonly GlobalSearchService $search) {}

    public function __invoke(GlobalSearchRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('search.view'), 403, 'You do not have permission to use global search.');
        $perPage = min(ApiResponse::perPage($request->validated('per_page', 25)), 50);
        $page = max(1, (int) $request->validated('page', 1));

        return ApiResponse::paginated($this->search->search(
            $request->user(),
            $request->validated('q'),
            $request->validated('types', []),
            $perPage,
            $page,
        ));
    }
}
