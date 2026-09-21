<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CustomerPortal;
use App\Models\PortalInvitation;
use App\Models\PortalUser;
use App\Models\Tenant;
use App\Policies\CustomerPortalPolicy;
use App\Support\TenantContext;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\PortalTestCase;

class PortalConfigurationTest extends PortalTestCase
{
    use RefreshDatabase;

    public function test_get_settings_returns_not_found_before_configuration(): void
    {
        $client = $this->createTenantUser(['portal.manage']);

        $this->internalApi($client)
            ->getJson('/api/v1/customer-portal/settings')
            ->assertNotFound();
    }

    public function test_management_routes_require_an_explicit_tenant_header(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $portalUser = $this->createPortalUser($fixture, [
            'contact_id' => $fixture['contact']->id,
            'email' => 'tenant-header@example.test',
        ]);
        $headers = ['Authorization' => 'Bearer '.$fixture['token']];

        $this->getJson('/api/v1/customer-portal/settings', $headers)
            ->assertForbidden();
        $this->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Missing tenant header',
            'is_active' => true,
        ], $headers)->assertForbidden();
        $this->getJson('/api/v1/customer-portal/users', $headers)
            ->assertForbidden();
        $this->patchJson('/api/v1/customer-portal/users/'.$portalUser->id, [
            'status' => PortalUser::STATUS_SUSPENDED,
        ], $headers)->assertForbidden();
    }

    public function test_management_routes_require_an_internal_bearer(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $portalUser = $this->createPortalUser($fixture, [
            'contact_id' => $fixture['contact']->id,
            'email' => 'internal-bearer@example.test',
        ]);
        $headers = ['X-Tenant-ID' => (string) $fixture['tenant']->id];

        $this->getJson('/api/v1/customer-portal/settings', $headers)
            ->assertUnauthorized();
        $this->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Missing internal bearer',
            'is_active' => true,
        ], $headers)->assertUnauthorized();
        $this->getJson('/api/v1/customer-portal/users', $headers)
            ->assertUnauthorized();
        $this->patchJson('/api/v1/customer-portal/users/'.$portalUser->id, [
            'status' => PortalUser::STATUS_SUSPENDED,
        ], $headers)->assertUnauthorized();
    }

    public function test_manager_creates_one_portal_without_slug_or_editable_uuid(): void
    {
        $client = $this->createTenantUser(['portal.manage']);
        $api = $this->internalApi($client);

        $response = $api->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Portal Acme',
            'is_active' => true,
            'settings' => [
                'welcome_message' => 'Bienvenido',
                'support_email' => 'soporte@example.test',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Portal Acme')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.settings.welcome_message', 'Bienvenido')
            ->assertJsonMissingPath('data.slug');

        $publicId = $response->json('data.public_id');
        $this->assertIsString($publicId);
        $this->assertTrue(Str::isUuid($publicId));
        $this->assertFalse(Schema::hasColumn('customer_portals', 'slug'));
        $this->assertDatabaseCount('customer_portals', 1);
        $this->assertDatabaseHas('customer_portal_locators', [
            'public_id' => $publicId,
            'tenant_id' => $client['tenant']->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $client['tenant']->id,
            'user_id' => $client['user']->id,
            'action' => 'portal.settings.created',
        ]);
    }

    public function test_settings_update_preserves_uuid_and_replaces_supplied_settings(): void
    {
        $client = $this->createTenantUser(['portal.manage']);
        $api = $this->internalApi($client);
        $publicId = $api->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Portal inicial',
            'is_active' => true,
            'settings' => [
                'welcome_message' => 'Mensaje inicial',
                'support_email' => 'soporte@example.test',
            ],
        ])->assertCreated()->json('data.public_id');

        $api->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Portal actualizado',
            'is_active' => true,
            'settings' => ['welcome_message' => 'Mensaje nuevo'],
        ])->assertOk()
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.title', 'Portal actualizado')
            ->assertJsonPath('data.settings', ['welcome_message' => 'Mensaje nuevo']);

        $this->assertDatabaseCount('customer_portals', 1);
        $this->assertDatabaseCount('customer_portal_locators', 1);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $client['tenant']->id,
            'action' => 'portal.settings.updated',
        ]);
    }

    public function test_tenant_unique_creation_race_reloads_and_updates_the_winner(): void
    {
        $client = $this->createTenantUser(['portal.manage']);
        $winnerPublicId = (string) Str::uuid();
        $collisionRaised = false;
        $winnerInserted = false;

        CustomerPortal::creating(function () use (&$collisionRaised): void {
            if ($collisionRaised) {
                return;
            }

            $collisionRaised = true;
            throw (new UniqueConstraintViolationException(
                'sqlite',
                'insert into customer_portals (tenant_id) values (?)',
                [],
                new \PDOException('UNIQUE constraint failed: customer_portals.tenant_id'),
            ))->setColumns(['tenant_id']);
        });
        Event::listen(TransactionRolledBack::class, function () use (
            &$collisionRaised,
            &$winnerInserted,
            $client,
            $winnerPublicId,
        ): void {
            if (! $collisionRaised || $winnerInserted) {
                return;
            }

            $winnerInserted = true;
            $portal = CustomerPortal::create([
                'public_id' => $winnerPublicId,
                'title' => 'Concurrent winner',
                'is_active' => false,
                'settings' => ['welcome_message' => 'Winner'],
            ]);
            DB::table('customer_portal_locators')->insert([
                'public_id' => $winnerPublicId,
                'portal_id' => $portal->id,
                'tenant_id' => $client['tenant']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->internalApi($client)->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Requested settings',
            'is_active' => true,
            'settings' => ['welcome_message' => 'Requested'],
        ])->assertOk()
            ->assertJsonPath('data.public_id', $winnerPublicId)
            ->assertJsonPath('data.title', 'Requested settings')
            ->assertJsonPath('data.settings.welcome_message', 'Requested');

        $this->assertTrue($collisionRaised);
        $this->assertTrue($winnerInserted);
        $this->assertDatabaseCount('customer_portals', 1);
        $this->assertDatabaseCount('customer_portal_locators', 1);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $client['tenant']->id,
            'action' => 'portal.settings.updated',
        ]);
    }

    public function test_settings_reject_unknown_settings_and_server_controlled_fields(): void
    {
        $client = $this->createTenantUser(['portal.manage']);
        $api = $this->internalApi($client);
        $valid = ['title' => 'Portal seguro', 'is_active' => true];

        $api->putJson('/api/v1/customer-portal/settings', $valid + [
            'settings' => ['unknown_key' => 'forbidden'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['settings']);

        foreach ([
            'id' => 99,
            'tenant_id' => $client['tenant']->id,
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'slug' => 'client-chosen',
        ] as $field => $value) {
            $api->putJson('/api/v1/customer-portal/settings', $valid + [$field => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseCount('customer_portals', 0);
        $this->assertDatabaseCount('customer_portal_locators', 0);
    }

    public function test_internal_routes_require_portal_permission_and_active_membership(): void
    {
        $unprivileged = $this->portalFixture(['contacts.view']);
        $portalUser = $this->createPortalUser($unprivileged, [
            'contact_id' => $unprivileged['contact']->id,
            'email' => 'blocked@example.test',
        ]);
        $api = $this->internalApi($unprivileged);

        $api->getJson('/api/v1/customer-portal/settings')->assertForbidden();
        $api->putJson('/api/v1/customer-portal/settings', [
            'title' => 'No permitido', 'is_active' => true,
        ])->assertForbidden();
        $api->getJson('/api/v1/customer-portal/users')->assertForbidden();
        $api->patchJson('/api/v1/customer-portal/users/'.$portalUser->id, [
            'status' => PortalUser::STATUS_SUSPENDED,
        ])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $inactive = $this->portalFixture(['portal.manage']);
        $inactive['user']->memberships()->update(['status' => 'inactive']);
        $inactiveApi = $this->internalApi($inactive);
        $inactiveApi->getJson('/api/v1/customer-portal/settings')->assertForbidden();
        $inactiveApi->getJson('/api/v1/customer-portal/users')->assertForbidden();
    }

    public function test_customer_portal_policy_bindings_are_tenant_aware(): void
    {
        $local = $this->portalFixture(['portal.manage']);
        $localPortalUser = $this->createPortalUser($local, [
            'contact_id' => $local['contact']->id,
            'email' => 'local-policy@example.test',
        ]);
        $localInvitation = PortalInvitation::create([
            'contact_id' => $local['contact']->id,
            'invited_by' => $local['user']->id,
            'email' => 'invited-policy@example.test',
            'token_hash' => hash('sha256', 'local-policy-invitation'),
            'expires_at' => now()->addHour(),
        ]);

        foreach ([CustomerPortal::class, PortalUser::class, PortalInvitation::class] as $model) {
            $this->assertInstanceOf(CustomerPortalPolicy::class, Gate::getPolicyFor($model));
        }
        $this->assertTrue(Gate::forUser($local['user'])->allows('update', $local['portal']));
        $this->assertTrue(Gate::forUser($local['user'])->allows('update', $localPortalUser));
        $this->assertTrue(Gate::forUser($local['user'])->allows('update', $localInvitation));

        $foreign = $this->portalFixture(['portal.manage']);
        app(TenantContext::class)->set((int) $local['tenant']->id);

        $this->assertFalse(Gate::forUser($local['user'])->allows('update', $foreign['portal']));
    }

    public function test_settings_and_portal_user_routes_are_tenant_isolated(): void
    {
        $foreign = $this->portalFixture(['portal.manage']);
        $foreignUser = $this->createPortalUser($foreign, [
            'contact_id' => $foreign['contact']->id,
            'email' => 'foreign@example.test',
        ]);

        $this->app['auth']->forgetGuards();
        $local = $this->portalFixture(['portal.manage']);
        $localUser = $this->createPortalUser($local, [
            'contact_id' => $local['contact']->id,
            'email' => 'local@example.test',
        ]);
        $api = $this->internalApi($local);

        $api->getJson('/api/v1/customer-portal/settings')->assertOk()
            ->assertJsonPath('data.public_id', $local['portal']->public_id);
        $api->getJson('/api/v1/customer-portal/users')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $localUser->id);
        $api->patchJson('/api/v1/customer-portal/users/'.$foreignUser->id, [
            'status' => PortalUser::STATUS_SUSPENDED,
        ])->assertNotFound();

        $this->assertDatabaseHas('portal_users', [
            'id' => $foreignUser->id,
            'tenant_id' => $foreign['tenant']->id,
            'status' => PortalUser::STATUS_ACTIVE,
        ]);

        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->clear();
        $emptyTenant = $this->createTenantUser(['portal.manage']);
        $this->internalApi($emptyTenant)
            ->getJson('/api/v1/customer-portal/settings')
            ->assertNotFound();
    }

    public function test_user_list_filters_paginates_and_treats_wildcards_literally(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $alpha = $this->createPortalUser(
            $fixture,
            ['email' => 'alpha@example.test'],
            ['first_name' => 'Needle', 'last_name' => 'Alpha'],
        );
        $bang = $this->createPortalUser($fixture, ['email' => 'bang!literal@example.test']);
        $percent = $this->createPortalUser($fixture, ['email' => 'percent%literal@example.test']);
        $underscore = $this->createPortalUser($fixture, [
            'email' => 'under_score@example.test',
            'status' => PortalUser::STATUS_SUSPENDED,
        ]);
        $api = $this->internalApi($fixture);

        foreach ([
            '%25' => $percent->id,
            '_' => $underscore->id,
            '!' => $bang->id,
            'needle' => $alpha->id,
        ] as $query => $expectedId) {
            $api->getJson('/api/v1/customer-portal/users?q='.$query)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $expectedId);
        }

        $api->getJson('/api/v1/customer-portal/users?status=SUSPENDED')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $underscore->id);
        $api->getJson('/api/v1/customer-portal/users?contact_id='.$alpha->contact_id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alpha->id);
        $api->getJson('/api/v1/customer-portal/users?status=ACTIVE&sort=email&direction=asc&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'bang!literal@example.test')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3);

        $resource = $api->getJson('/api/v1/customer-portal/users?q=alpha')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('data.0.contact.id', $alpha->contact_id)
            ->assertJsonPath('data.0.contact.first_name', 'Needle')
            ->assertJsonMissingPath('data.0.tenant_id')
            ->assertJsonMissingPath('data.0.contact.tenant_id')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.tokens');
        $this->assertStringNotContainsString('portal-password', $resource->getContent());

        foreach ([
            ['q', str_repeat('x', 121)],
            ['status', 'DISABLED'],
            ['contact_id', 0],
            ['sort', 'password'],
            ['direction', 'sideways'],
            ['page', 0],
            ['per_page', 0],
            ['per_page', 101],
        ] as [$field, $invalid]) {
            $api->getJson('/api/v1/customer-portal/users?'.http_build_query([$field => $invalid]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_status_update_rejects_unsupported_status_and_server_fields(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $portalUser = $this->createPortalUser($fixture, [
            'contact_id' => $fixture['contact']->id,
            'email' => 'immutable@example.test',
        ]);
        $api = $this->internalApi($fixture);
        $uri = '/api/v1/customer-portal/users/'.$portalUser->id;

        $api->patchJson($uri, ['status' => 'DISABLED'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        foreach ([
            'tenant_id' => $fixture['tenant']->id,
            'contact_id' => Contact::factory()->create([
                'tenant_id' => $fixture['tenant']->id,
            ])->id,
            'email' => 'changed@example.test',
            'password' => 'changed-password',
        ] as $field => $value) {
            $api->patchJson($uri, [
                'status' => PortalUser::STATUS_SUSPENDED,
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        $portalUser->refresh();
        $this->assertSame(PortalUser::STATUS_ACTIVE, $portalUser->status);
        $this->assertSame('immutable@example.test', $portalUser->email);
        $this->assertSame($fixture['contact']->id, $portalUser->contact_id);
    }

    public function test_suspension_revokes_tokens_and_reactivation_does_not_issue_one(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $portalUser = $this->createPortalUser($fixture, [
            'contact_id' => $fixture['contact']->id,
            'email' => 'status@example.test',
        ]);
        $portalUser->createToken('first portal token');
        $portalUser->createToken('second portal token');
        $this->assertSame(2, $portalUser->tokens()->count());
        $revokedBeforeAudit = false;
        AuditLog::creating(function (AuditLog $audit) use ($portalUser, &$revokedBeforeAudit): void {
            if ($audit->action === 'portal.user.suspended') {
                $revokedBeforeAudit = $portalUser->tokens()->count() === 0;
            }
        });
        $api = $this->internalApi($fixture);

        $api->patchJson('/api/v1/customer-portal/users/'.$portalUser->id, [
            'status' => PortalUser::STATUS_SUSPENDED,
        ])->assertOk()
            ->assertJsonPath('data.status', PortalUser::STATUS_SUSPENDED)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.tokens');

        $this->assertSame(0, $portalUser->tokens()->count());
        $this->assertTrue($revokedBeforeAudit, 'Portal-user tokens must be deleted before suspension is audited.');
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'action' => 'portal.user.suspended',
        ]);

        $api->patchJson('/api/v1/customer-portal/users/'.$portalUser->id, [
            'status' => PortalUser::STATUS_ACTIVE,
        ])->assertOk()->assertJsonPath('data.status', PortalUser::STATUS_ACTIVE);

        $this->assertSame(0, $portalUser->tokens()->count());
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'action' => 'portal.user.reactivated',
        ]);
    }

    public function test_disabling_portal_revokes_every_tenant_portal_token(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $first = $this->createPortalUser($fixture, [
            'contact_id' => $fixture['contact']->id,
            'email' => 'first@example.test',
        ]);
        $second = $this->createPortalUser($fixture, ['email' => 'second@example.test']);
        $first->createToken('first');
        $second->createToken('second');
        $revokedBeforeAudit = false;
        AuditLog::creating(function (AuditLog $audit) use ($first, $second, &$revokedBeforeAudit): void {
            if ($audit->action === 'portal.settings.disabled') {
                $revokedBeforeAudit = $first->tokens()->count() === 0
                    && $second->tokens()->count() === 0;
            }
        });

        $this->internalApi($fixture)->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Portal desactivado',
            'is_active' => false,
            'settings' => ['welcome_message' => 'Temporalmente cerrado'],
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $first->tokens()->count());
        $this->assertSame(0, $second->tokens()->count());
        $this->assertTrue($revokedBeforeAudit, 'Tenant portal tokens must be deleted before deactivation is audited.');
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $fixture['tenant']->id,
            'action' => 'portal.settings.disabled',
        ]);
    }

    public function test_disabling_portal_does_not_revoke_foreign_tenant_tokens(): void
    {
        $foreign = $this->portalFixture(['portal.manage']);
        $foreignUser = $this->createPortalUser($foreign, [
            'contact_id' => $foreign['contact']->id,
            'email' => 'foreign-token@example.test',
        ]);
        $foreignUser->createToken('foreign token');

        $this->app['auth']->forgetGuards();
        $local = $this->portalFixture(['portal.manage']);
        $localUser = $this->createPortalUser($local, [
            'contact_id' => $local['contact']->id,
            'email' => 'local-token@example.test',
        ]);
        $localUser->createToken('local token');

        $this->internalApi($local)->putJson('/api/v1/customer-portal/settings', [
            'title' => 'Local portal disabled',
            'is_active' => false,
        ])->assertOk();

        $this->assertSame(0, $localUser->tokens()->count());
        $this->assertSame(1, $foreignUser->tokens()->count());
    }

    public function test_status_and_deactivation_roll_back_token_revocation_when_audit_fails(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $portalUser = $this->createPortalUser($fixture, [
            'contact_id' => $fixture['contact']->id,
            'email' => 'rollback@example.test',
        ]);
        $portalUser->createToken('must survive rollback');
        AuditLog::creating(function (AuditLog $audit): void {
            if (in_array($audit->action, ['portal.settings.disabled', 'portal.user.suspended'], true)) {
                throw new RuntimeException('Forced audit failure.');
            }
        });
        $api = $this->internalApi($fixture);
        $this->withoutExceptionHandling();

        try {
            $api->putJson('/api/v1/customer-portal/settings', [
                'title' => 'Must roll back',
                'is_active' => false,
            ]);
            $this->fail('The forced portal audit failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced audit failure.', $exception->getMessage());
        }

        $this->assertTrue((bool) CustomerPortal::withoutGlobalScopes()->findOrFail($fixture['portal']->id)->is_active);
        $this->assertSame(1, $portalUser->tokens()->count());

        try {
            $api->patchJson('/api/v1/customer-portal/users/'.$portalUser->id, [
                'status' => PortalUser::STATUS_SUSPENDED,
            ]);
            $this->fail('The forced portal-user audit failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced audit failure.', $exception->getMessage());
        }

        $this->assertSame(PortalUser::STATUS_ACTIVE, $portalUser->fresh()->status);
        $this->assertSame(1, $portalUser->tokens()->count());
    }

    public function test_portal_principal_is_rejected_before_tenant_membership_lookup(): void
    {
        $fixture = $this->portalFixture(['contacts.view', 'portal.manage']);
        $portalUser = $this->createPortalUser($fixture, [
            'contact_id' => $fixture['contact']->id,
            'email' => 'principal@example.test',
        ]);
        $this->assertSame($fixture['user']->id, $portalUser->id, 'The fixture must collide numeric principal IDs.');
        $portalToken = $portalUser->createToken('portal principal')->plainTextToken;
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($portalToken)
            ->withHeader('X-Tenant-ID', (string) $fixture['tenant']->id)
            ->getJson('/api/v1/contacts');

        $this->assertFalse(
            collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'tenant_user')),
            'ResolveTenant queried tenant_user before rejecting a PortalUser principal.',
        );
        $response->assertForbidden()
            ->assertJsonPath('message', 'This principal cannot access the internal API.');
    }

    /**
     * @param  array{tenant: Tenant}  $fixture
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $contactAttributes
     */
    private function createPortalUser(
        array $fixture,
        array $attributes = [],
        array $contactAttributes = [],
    ): PortalUser {
        app(TenantContext::class)->set((int) $fixture['tenant']->id);
        if (! array_key_exists('contact_id', $attributes)) {
            $contact = Contact::factory()->create(array_merge([
                'tenant_id' => $fixture['tenant']->id,
            ], $contactAttributes));
            $attributes['contact_id'] = $contact->id;
        }

        return PortalUser::create(array_merge([
            'email' => fake()->unique()->safeEmail(),
            'password' => 'portal-password',
            'status' => PortalUser::STATUS_ACTIVE,
        ], $attributes));
    }
}
