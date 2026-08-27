<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\PermissionCatalog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$user, $tenant, $token] = DB::transaction(function () use ($data): array {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $tenant = Tenant::create([
                'name' => $data['tenant_name'],
                'industry' => $data['industry'] ?? config('tenancy.default_industry'),
                'status' => 'active',
                'settings' => [],
            ]);

            $permissionIds = PermissionCatalog::ensure();
            $role = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Administrator',
                'description' => 'Full access to the tenant.',
                'is_system' => true,
            ]);
            $role->permissions()->sync($permissionIds);
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return [$user, $tenant, $user->createToken('crm-web')->plainTextToken];
        });
        $this->audit->record('create', 'Tenant', $tenant->id, newValues: ['name' => $tenant->name], tenantId: $tenant->id);

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->sessionUser($user->fresh(), $tenant),
            'tenant' => $tenant,
        ], [], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('Invalid credentials.', [], 401);
        }

        $tenantQuery = $user->tenants()->wherePivot('status', 'active');
        if (! empty($data['tenant_id'])) {
            $tenantQuery->where('tenants.id', $data['tenant_id']);
        }
        $tenant = $tenantQuery->first();
        if ($tenant === null) {
            return ApiResponse::error('The user has no access to the requested tenant.', [], 403);
        }

        $user->tokens()->where('name', $data['device_name'] ?? 'crm-web')->delete();
        $token = $user->createToken($data['device_name'] ?? 'crm-web')->plainTextToken;
        $this->audit->record('login', 'User', $user->id, newValues: ['device_name' => $data['device_name'] ?? 'crm-web'], tenantId: $tenant->id);

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->sessionUser($user, $tenant),
            'tenant' => $tenant,
        ]);
    }

    public function me(): JsonResponse
    {
        $tenant = request()->attributes->get('tenant');

        return ApiResponse::success([
            'user' => $this->sessionUser(request()->user()->load('tenants'), $tenant),
            'tenant' => $tenant,
        ]);
    }

    public function logout(): JsonResponse
    {
        $token = request()->user()->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }
        $this->audit->record('logout', 'User', request()->user()->id);

        return ApiResponse::success(['logged_out' => true]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();
        $this->audit->record('password_change', 'User', $request->user()->id);

        return ApiResponse::success(['updated' => true]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return ApiResponse::success(['message' => 'If the account exists, a reset link will be sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('The password reset token is invalid or expired.', ['email' => [$status]], 422);
        }

        return ApiResponse::success(['reset' => true]);
    }

    private function sessionUser(User $user, ?Tenant $tenant): User
    {
        if ($user->is_platform_admin) {
            $user->setAttribute('permissions', PermissionCatalog::ALL);

            return $user;
        }

        if ($tenant === null) {
            $user->setAttribute('permissions', []);

            return $user;
        }

        $membership = $user->memberships()
            ->with('role.permissions')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();
        $role = $membership?->role;
        $user->setAttribute('role', $role);
        $user->setAttribute('permissions', $role?->permissions?->pluck('key')->values()->all() ?? []);

        return $user;
    }
}
