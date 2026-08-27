<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TenantUser;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('users.manage'), 403, 'You do not have permission to manage users.');
        $users = TenantUser::query()->with(['user:id,name,email', 'role:id,name'])->where('tenant_id', app(TenantContext::class)->requireId())->paginate(ApiResponse::perPage(request('per_page', 25)));

        return ApiResponse::paginated($users);
    }

    public function updateRole(Request $request, int $userId): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403, 'You do not have permission to manage users.');
        $request->validate(['role_id' => ['required', 'integer', 'exists:roles,id'], 'status' => ['sometimes', 'in:active,suspended']]);
        $membership = TenantUser::query()->where('tenant_id', app(TenantContext::class)->requireId())->where('user_id', $userId)->firstOrFail();
        $role = Role::query()
            ->where('tenant_id', app(TenantContext::class)->requireId())
            ->findOrFail($request->integer('role_id'));
        abort_unless((int) $membership->user_id !== (int) $request->user()->id || $request->user()->hasPermission('settings.manage'), 422, 'You cannot remove your own administrative access.');
        $old = $membership->getAttributes();
        $membership->update(['role_id' => $role->id, 'status' => $request->input('status', $membership->status)]);
        $this->audit->record('permission_change', 'TenantUser', $membership->id, oldValues: $old, newValues: $membership->getAttributes());

        return ApiResponse::success($membership->fresh()->load(['user:id,name,email', 'role:id,name']));
    }
}
