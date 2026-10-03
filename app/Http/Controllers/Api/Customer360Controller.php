<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactTimelineRequest;
use App\Http\Requests\CustomerOverviewRequest;
use App\Models\Contact;
use App\Services\Customer360Service;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class Customer360Controller extends Controller
{
    public function __construct(private readonly Customer360Service $customer360) {}

    public function overview(CustomerOverviewRequest $request, int $contact): JsonResponse
    {
        $model = Contact::findOrFail($contact);
        $this->authorize('view', $model);

        return ApiResponse::success($this->customer360->overview(
            $model,
            $request->user(),
            (int) $request->validated('recent_limit', 5),
        ));
    }

    public function timeline(ContactTimelineRequest $request, int $contact): JsonResponse
    {
        $model = Contact::findOrFail($contact);
        $this->authorize('view', $model);
        $perPage = ApiResponse::perPage($request->validated('per_page', 25));
        $page = max(1, (int) $request->validated('page', 1));

        return ApiResponse::paginated($this->customer360->timeline(
            $model,
            $request->user(),
            $request->validated(),
            $perPage,
            $page,
        ));
    }
}
