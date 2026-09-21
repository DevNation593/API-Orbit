<?php

namespace App\Services;

use App\Models\CustomerPortal;
use App\Models\PortalUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerPortalService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{portal: CustomerPortal, created: bool} */
    public function upsert(array $data, User $actor): array
    {
        try {
            return $this->persistPortal($data, $actor);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->violatesConstraint(
                $exception,
                'customer_portals_tenant_unique',
                ['tenant_id'],
            )) {
                throw $exception;
            }

            return $this->persistPortal($data, $actor);
        }
    }

    public function changeStatus(
        PortalUser $portalUser,
        string $status,
        User $actor,
    ): PortalUser {
        return DB::transaction(function () use ($portalUser, $status, $actor): PortalUser {
            $tenantId = app(TenantContext::class)->requireId();
            $portalUser = PortalUser::forTenant($tenantId)
                ->lockForUpdate()
                ->findOrFail($portalUser->getKey());
            $old = $this->portalUserAuditValues($portalUser);
            $portalUser->status = $status;
            $portalUser->save();

            if ($status === PortalUser::STATUS_SUSPENDED) {
                $portalUser->tokens()->delete();
            }

            $portalUser->refresh();
            $this->audit->record(
                $status === PortalUser::STATUS_SUSPENDED
                    ? 'portal.user.suspended'
                    : 'portal.user.reactivated',
                $portalUser,
                oldValues: $old,
                newValues: $this->portalUserAuditValues($portalUser),
                actor: $actor,
            );

            return $portalUser;
        });
    }

    /** @return array{portal: CustomerPortal, created: bool} */
    private function persistPortal(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            $tenantId = app(TenantContext::class)->requireId();
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $portal = CustomerPortal::forTenant($tenantId)
                ->lockForUpdate()
                ->firstOrNew(['tenant_id' => $tenantId]);
            $created = ! $portal->exists;
            $wasActive = (bool) $portal->is_active;
            $old = $created ? null : $this->portalAuditValues($portal);

            if ($created) {
                $portal->public_id = (string) Str::uuid();
            }

            $portal->fill(Arr::only($data, ['title', 'is_active', 'settings']))->save();

            if ($created) {
                DB::table('customer_portal_locators')->insert([
                    'public_id' => $portal->public_id,
                    'portal_id' => $portal->id,
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $disabled = ! $created && $wasActive && ! $portal->is_active;
            if ($disabled) {
                $this->revokeTenantPortalTokens($tenantId);
            }

            $portal->refresh();
            $this->audit->record(
                $created
                    ? 'portal.settings.created'
                    : ($disabled ? 'portal.settings.disabled' : 'portal.settings.updated'),
                $portal,
                oldValues: $old,
                newValues: $this->portalAuditValues($portal),
                actor: $actor,
            );

            return ['portal' => $portal, 'created' => $created];
        });
    }

    private function revokeTenantPortalTokens(int $tenantId): void
    {
        PortalUser::forTenant($tenantId)
            ->lockForUpdate()
            ->get()
            ->each(fn (PortalUser $portalUser) => $portalUser->tokens()->delete());
    }

    private function portalAuditValues(CustomerPortal $portal): array
    {
        return Arr::only($portal->attributesToArray(), [
            'id', 'tenant_id', 'public_id', 'title', 'is_active', 'settings',
        ]);
    }

    private function portalUserAuditValues(PortalUser $portalUser): array
    {
        return Arr::only($portalUser->attributesToArray(), [
            'id', 'tenant_id', 'contact_id', 'email', 'status', 'email_verified_at',
            'last_login_at',
        ]);
    }

    /** @param list<string> $columns */
    private function violatesConstraint(
        UniqueConstraintViolationException $exception,
        string $index,
        array $columns,
    ): bool {
        return $exception->index === $index
            || ($exception->index === null && $exception->columns === $columns);
    }
}
