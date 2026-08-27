<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\PermissionCatalog;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly DatabaseManager $database) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(request()->user()->tenants()->wherePivot('status', 'active')->get());
    }

    public function current(): JsonResponse
    {
        return ApiResponse::success(request()->attributes->get('tenant'));
    }

    public function store(TenantRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenant = $this->database->transaction(function () use ($data, $request): Tenant {
            $tenant = Tenant::create($data + ['status' => 'active']);
            $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Administrator', 'is_system' => true]);
            $role->permissions()->sync(PermissionCatalog::ensure());
            TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

            return $tenant;
        });
        $this->audit->record('create', 'Tenant', $tenant->id, newValues: ['name' => $tenant->name], tenantId: $tenant->id);

        return ApiResponse::success($tenant, [], 201);
    }
}
