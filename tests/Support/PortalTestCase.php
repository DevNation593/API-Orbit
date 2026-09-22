<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\CustomerPortal;
use App\Models\PortalUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class PortalTestCase extends TestCase
{
    /**
     * @return array{user: User, tenant: Tenant, token: string, portal: CustomerPortal, contact: Contact}
     */
    protected function portalFixture(
        array $permissions = PermissionCatalog::ALL,
        bool $active = true,
    ): array {
        app(TenantContext::class)->clear();
        $client = $this->createTenantUser($permissions);
        app(TenantContext::class)->set((int) $client['tenant']->id);

        $portal = CustomerPortal::create([
            'public_id' => (string) Str::uuid(),
            'title' => 'Portal de clientes',
            'is_active' => $active,
            'settings' => ['welcome_message' => 'Bienvenido'],
        ]);
        DB::table('customer_portal_locators')->insert([
            'public_id' => $portal->public_id,
            'portal_id' => $portal->id,
            'tenant_id' => $client['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contact = Contact::factory()->create([
            'tenant_id' => $client['tenant']->id,
            'first_name' => 'Cliente',
            'last_name' => 'Principal',
            'email' => 'cliente-'.$client['tenant']->id.'@example.test',
        ]);

        return $client + ['portal' => $portal, 'contact' => $contact];
    }

    /** @param array{token: string, tenant: Tenant} $fixture */
    protected function internalApi(array $fixture): static
    {
        return $this->withToken($fixture['token'])
            ->withHeader('X-Tenant-ID', (string) $fixture['tenant']->id);
    }

    /** @param array{tenant: Tenant, contact: Contact} $fixture */
    protected function createPortalUser(
        array $fixture,
        string $password = 'Portal-Password!2026',
    ): PortalUser {
        app(TenantContext::class)->set((int) $fixture['tenant']->id);

        return PortalUser::create([
            'contact_id' => $fixture['contact']->id,
            'email' => mb_strtolower(trim((string) $fixture['contact']->email)),
            'password' => $password,
            'status' => PortalUser::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array{tenant: Tenant, contact: Contact}  $fixture
     * @return array{user: PortalUser, token: string, expires_at: CarbonImmutable}
     */
    protected function portalSession(
        array $fixture,
        string $password = 'Portal-Password!2026',
    ): array {
        $user = $this->createPortalUser($fixture, $password);
        $expiresAt = CarbonImmutable::now()->addDays(30);
        $token = $user->createToken('portal test', ['portal'], $expiresAt)->plainTextToken;

        return ['user' => $user, 'token' => $token, 'expires_at' => $expiresAt];
    }
}
