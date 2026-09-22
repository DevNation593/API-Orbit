<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalInvitationAcceptRequest;
use App\Http\Resources\PortalProfileResource;
use App\Http\Resources\PortalSessionResource;
use App\Services\PortalInvitationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalInvitationController extends Controller
{
    public function __construct(
        private readonly PortalInvitationService $invitationService,
    ) {}

    public function show(Request $request, string $token): JsonResponse
    {
        return ApiResponse::success(
            (new PortalProfileResource(
                $this->invitationService->inspect($token),
            ))->resolve($request),
        );
    }

    public function accept(
        PortalInvitationAcceptRequest $request,
        string $token,
    ): JsonResponse {
        return ApiResponse::success(
            (new PortalSessionResource(
                $this->invitationService->accept($token, $request->validated()),
            ))->resolve($request),
            [],
            201,
        );
    }
}
