<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesAnalyticsRequest;
use App\Services\SalesAnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SalesAnalyticsController extends Controller
{
    public function __construct(private readonly SalesAnalyticsService $analytics) {}

    public function __invoke(SalesAnalyticsRequest $request): JsonResponse
    {
        return ApiResponse::success($this->analytics->report($request->validated()));
    }
}
