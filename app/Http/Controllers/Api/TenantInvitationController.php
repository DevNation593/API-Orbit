<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Requests\InviteUserRequest;
use App\Models\Role;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class TenantInvitationController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditService $audit,
    ) {}

    public function index(): JsonResponse
    {
        $this->assertPermission();

        return ApiResponse::success(
            TenantInvitation::query()
                ->with(['role:id,name', 'inviter:id,name'])
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->latest()
                ->get(),
        );
    }

    public function store(InviteUserRequest $request): JsonResponse
    {
        $this->assertPermission();
        $tenantId = app(TenantContext::class)->requireId();
        $email = $request->validated('email');
        $role = Role::query()->where('tenant_id', $tenantId)->findOrFail($request->integer('role_id'));

        $alreadyMember = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'Este usuario ya pertenece al equipo.']);
        }

        $rawToken = Str::random(64);
        $invitation = $this->database->transaction(function () use ($request, $tenantId, $email, $role, $rawToken): TenantInvitation {
            TenantInvitation::query()
                ->where('email', $email)
                ->where('status', 'pending')
                ->update(['status' => 'revoked']);

            return TenantInvitation::create([
                'tenant_id' => $tenantId,
                'role_id' => $role->id,
                'invited_by' => $request->user()->id,
                'email' => $email,
                'token_hash' => hash('sha256', $rawToken),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);
        });

        $invitation->load(['tenant:id,name', 'role:id,name', 'inviter:id,name']);
        $acceptanceUrl = rtrim((string) config('app.frontend_url'), '/').'/accept-invitation/'.$rawToken;
        $notificationSent = true;

        try {
            Notification::route('mail', $email)->notify(
                new TenantInvitationNotification($invitation, $acceptanceUrl),
            );
        } catch (Throwable $exception) {
            report($exception);
            $notificationSent = false;
        }

        $this->audit->record('invite', $invitation, newValues: [
            'email' => $email,
            'role_id' => $role->id,
            'expires_at' => $invitation->expires_at?->toISOString(),
        ]);

        $meta = ['notification_sent' => $notificationSent];
        if (app()->isLocal() || app()->environment('testing')) {
            $meta['acceptance_url'] = $acceptanceUrl;
        }

        return ApiResponse::success($invitation, $meta, 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->assertPermission();
        $invitation = TenantInvitation::query()->findOrFail($id);
        abort_unless($invitation->status === 'pending', 422, 'Only pending invitations can be revoked.');
        $invitation->update(['status' => 'revoked']);
        $this->audit->record('invite_revoked', $invitation, oldValues: ['status' => 'pending'], newValues: ['status' => 'revoked']);

        return ApiResponse::success(['revoked' => true]);
    }

    public function show(string $token): JsonResponse
    {
        $invitation = $this->findInvitation($token);
        if ($invitation === null) {
            return ApiResponse::error('La invitación no existe o ya fue utilizada.', ['token' => ['Invitación inválida.']], 404);
        }
        if ($invitation->expires_at->isPast()) {
            return ApiResponse::error('La invitación ha vencido.', ['token' => ['Solicita una nueva invitación.']], 410);
        }

        return ApiResponse::success([
            'email' => $invitation->email,
            'tenant' => $invitation->tenant->only(['id', 'name']),
            'role' => $invitation->role->only(['id', 'name']),
            'expires_at' => $invitation->expires_at,
            'existing_user' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $preview = $this->findInvitation($token);
        if ($preview === null || $preview->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => 'La invitación no existe, venció o ya fue utilizada.']);
        }

        $existingUser = User::query()->where('email', $preview->email)->first();
        if ($existingUser === null) {
            $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
        } elseif (! Hash::check((string) $request->input('password'), (string) $existingUser->password)) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña no es correcta para esta cuenta.',
            ]);
        }

        [$user, $tenant, $plainTextToken] = $this->database->transaction(function () use ($request, $token): array {
            $invitation = TenantInvitation::withoutGlobalScopes()
                ->with(['tenant', 'role.permissions'])
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'La invitación no existe, venció o ya fue utilizada.']);
            }

            $user = User::query()->where('email', $invitation->email)->first();
            if ($user === null) {
                $user = User::create([
                    'name' => $request->string('name')->toString(),
                    'email' => $invitation->email,
                    'password' => $request->input('password'),
                ]);
            } elseif (! Hash::check((string) $request->input('password'), (string) $user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'La contraseña no es correcta para esta cuenta.',
                ]);
            }

            TenantUser::query()->updateOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['role_id' => $invitation->role_id, 'status' => 'active', 'joined_at' => now()],
            );
            $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);

            $role = $invitation->role;
            $user->load('tenants');
            $user->setAttribute('role', $role);
            $user->setAttribute('permissions', $role->permissions->pluck('key')->values()->all());

            return [$user, $invitation->tenant, $user->createToken('vantex-invitation')->plainTextToken];
        });

        $this->audit->record('invite_accepted', 'TenantUser', $user->id, newValues: ['email' => $user->email], tenantId: $tenant->id);

        return ApiResponse::success([
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'user' => $user,
            'tenant' => $tenant,
        ], [], 201);
    }

    private function findInvitation(string $token): ?TenantInvitation
    {
        return TenantInvitation::withoutGlobalScopes()
            ->with(['tenant:id,name', 'role:id,name'])
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->first();
    }

    private function assertPermission(): void
    {
        abort_unless(request()->user()->hasPermission('users.manage'), 403, 'You do not have permission to manage users.');
    }
}
