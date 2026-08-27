<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('roles.manage'), 403, 'You do not have permission to manage roles.');

        return ApiResponse::success(Role::query()->where('tenant_id', app(TenantContext::class)->requireId())->with('permissions:id,key,description')->orderBy('name')->get());
    }

    public function store(RoleRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('roles.manage'), 403, 'You do not have permission to manage roles.');
        $data = $request->validated();
        $permissionKeys = $data['permission_keys'];
        unset($data['permission_keys']);
        $role = Role::create($data + ['tenant_id' => app(TenantContext::class)->requireId()]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));
        $this->audit->record('role_change', 'Role', $role->id, newValues: ['name' => $role->name, 'permissions' => $permissionKeys]);

        return ApiResponse::success($role->load('permissions:id,key,description'), [], 201);
    }

    public function update(RoleRequest $request, int $id): JsonResponse
    {
        abort_unless($request->user()->hasPermission('roles.manage'), 403, 'You do not have permission to manage roles.');
        $role = Role::query()->where('tenant_id', app(TenantContext::class)->requireId())->findOrFail($id);
        abort_unless(! $role->is_system, 422, 'System roles cannot be edited.');
        $data = $request->validated();
        $permissionKeys = $data['permission_keys'];
        unset($data['permission_keys']);
        $old = $role->getAttributes();
        $role->update($data);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));
        $this->audit->record('role_change', 'Role', $role->id, oldValues: $old, newValues: ['name' => $role->name, 'permissions' => $permissionKeys]);

        return ApiResponse::success($role->fresh()->load('permissions:id,key,description'));
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('roles.manage'), 403, 'You do not have permission to manage roles.');
        $role = Role::query()->where('tenant_id', app(TenantContext::class)->requireId())->findOrFail($id);
        abort_unless(! $role->is_system, 422, 'System roles cannot be deleted.');
        $role->delete();
        $this->audit->record('role_change', 'Role', $role->id, oldValues: ['name' => $role->name]);

        return ApiResponse::success(['deleted' => true]);
    }
}
