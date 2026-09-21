<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\CustomerPortal;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\TenantContext;
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
}
