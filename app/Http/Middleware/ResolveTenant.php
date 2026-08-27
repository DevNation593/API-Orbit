<?php

namespace App\Http\Middleware;

use App\Models\TenantUser;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('Unauthenticated.', [], 401);
        }

        $requestedTenant = $request->header((string) config('tenancy.header', 'X-Tenant-ID'));
        $membershipQuery = TenantUser::query()
            ->with('tenant')
            ->where('user_id', $user->getKey())
            ->where('status', 'active');

        if ($requestedTenant !== null) {
            if (! ctype_digit((string) $requestedTenant)) {
                return ApiResponse::error('The selected tenant is invalid.', [], 403);
            }

            $membershipQuery->where('tenant_id', (int) $requestedTenant);
        }

        $membership = $membershipQuery->first();
        if ($membership?->tenant === null || $membership->tenant->status !== 'active') {
            return ApiResponse::error('The user has no access to the selected tenant.', [], 403);
        }

        $context = app(TenantContext::class);
        $context->set((int) $membership->tenant_id);
        $request->attributes->set('tenant', $membership->tenant);
        $request->attributes->set('membership', $membership);

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
