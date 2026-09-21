<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\CustomerPortal;
use App\Models\Permission;
use App\Models\PortalInvitation;
use App\Models\PortalPasswordResetToken;
use App\Models\PortalUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class PortalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_schema_is_tenant_safe_and_has_no_slug(): void
    {
        $columns = [
            'customer_portals' => [
                'id', 'tenant_id', 'public_id', 'title', 'is_active', 'settings',
                'created_at', 'updated_at',
            ],
            'portal_users' => [
                'id', 'tenant_id', 'contact_id', 'email', 'password', 'status',
                'email_verified_at', 'last_login_at', 'created_at', 'updated_at',
            ],
            'portal_invitations' => [
                'id', 'tenant_id', 'contact_id', 'invited_by', 'email', 'token_hash',
                'status', 'expires_at', 'accepted_at', 'created_at', 'updated_at',
            ],
            'portal_password_reset_tokens' => [
                'id', 'tenant_id', 'portal_user_id', 'token_hash', 'expires_at',
                'used_at', 'created_at', 'updated_at',
            ],
            'customer_portal_locators' => [
                'public_id', 'portal_id', 'tenant_id', 'created_at', 'updated_at',
            ],
            'portal_invitation_locators' => [
                'token_hash', 'invitation_id', 'tenant_id', 'created_at', 'updated_at',
            ],
        ];

        foreach ($columns as $table => $expectedColumns) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table} table.");
            $this->assertTrue(
                Schema::hasColumns($table, $expectedColumns),
                "Missing required columns on {$table}.",
            );
            $this->assertFalse(
                Schema::hasColumn($table, 'slug'),
                "The public portal contract must not expose a slug on {$table}.",
            );
        }

        $this->assertTrue(Schema::hasColumn('audit_logs', 'portal_user_id'));
    }

    public function test_same_portal_email_is_allowed_in_different_tenants(): void
    {
        [$tenantA, $contactA] = $this->tenantWithContact('contact-a@example.test');
        [$tenantB, $contactB] = $this->tenantWithContact('contact-b@example.test');

        $this->createPortalUser($tenantA, $contactA, 'shared@example.test');
        $this->createPortalUser($tenantB, $contactB, 'shared@example.test');

        $this->assertSame(
            2,
            PortalUser::withoutGlobalScopes()->where('email', 'shared@example.test')->count(),
        );
    }

    public function test_duplicate_portal_email_is_rejected_within_a_tenant(): void
    {
        [$tenant, $firstContact] = $this->tenantWithContact('first@example.test');
        $secondContact = Contact::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'second@example.test',
        ]);
        $this->createPortalUser($tenant, $firstContact, 'duplicate@example.test');

        $this->assertConstraintRejected(
            fn () => $this->createPortalUser($tenant, $secondContact, 'duplicate@example.test'),
            'The database accepted a duplicate portal email in one tenant.',
        );
    }

    public function test_duplicate_portal_contact_is_rejected_within_a_tenant(): void
    {
        [$tenant, $contact] = $this->tenantWithContact('contact@example.test');
        $this->createPortalUser($tenant, $contact, 'first-login@example.test');

        $this->assertConstraintRejected(
            fn () => $this->createPortalUser($tenant, $contact, 'second-login@example.test'),
            'The database accepted two portal accounts for one tenant contact.',
        );
    }

    public function test_each_tenant_has_at_most_one_customer_portal(): void
    {
        $tenant = Tenant::factory()->create();
        CustomerPortal::create([
            'tenant_id' => $tenant->id,
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'title' => 'Primary portal',
        ]);

        $this->assertConstraintRejected(
            fn () => CustomerPortal::create([
                'tenant_id' => $tenant->id,
                'public_id' => '22222222-2222-4222-8222-222222222222',
                'title' => 'Duplicate portal',
            ]),
            'The database accepted two customer portals for one tenant.',
        );
    }

    public function test_composite_foreign_keys_reject_cross_tenant_associations(): void
    {
        [$tenantA] = $this->tenantWithContact('tenant-a@example.test');
        [$tenantB, $contactB] = $this->tenantWithContact('tenant-b@example.test');
        $inviter = User::factory()->create();
        $portalUserB = $this->createPortalUser($tenantB, $contactB, 'portal-b@example.test');
        $portalB = CustomerPortal::create([
            'tenant_id' => $tenantB->id,
            'public_id' => '33333333-3333-4333-8333-333333333333',
            'title' => 'Tenant B portal',
        ]);
        $invitationB = PortalInvitation::create([
            'tenant_id' => $tenantB->id,
            'contact_id' => $contactB->id,
            'invited_by' => $inviter->id,
            'email' => 'invite-b@example.test',
            'token_hash' => str_repeat('b', 64),
            'expires_at' => now()->addDay(),
        ]);

        $operations = [
            'portal user to contact' => fn () => $this->createPortalUser(
                $tenantA,
                $contactB,
                'cross-contact@example.test',
            ),
            'invitation to contact' => fn () => PortalInvitation::create([
                'tenant_id' => $tenantA->id,
                'contact_id' => $contactB->id,
                'invited_by' => $inviter->id,
                'email' => 'cross-invite@example.test',
                'token_hash' => str_repeat('c', 64),
                'expires_at' => now()->addDay(),
            ]),
            'reset to portal user' => fn () => PortalPasswordResetToken::create([
                'tenant_id' => $tenantA->id,
                'portal_user_id' => $portalUserB->id,
                'token_hash' => str_repeat('d', 64),
                'expires_at' => now()->addHour(),
            ]),
            'portal locator to portal' => fn () => DB::table('customer_portal_locators')->insert([
                'public_id' => '44444444-4444-4444-8444-444444444444',
                'portal_id' => $portalB->id,
                'tenant_id' => $tenantA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'invitation locator to invitation' => fn () => DB::table('portal_invitation_locators')->insert([
                'token_hash' => str_repeat('e', 64),
                'invitation_id' => $invitationB->id,
                'tenant_id' => $tenantA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        ];

        foreach ($operations as $relationship => $operation) {
            $this->assertConstraintRejected(
                $operation,
                "The database accepted a cross-tenant {$relationship} relationship.",
            );
        }
    }

    public function test_user_and_portal_user_with_the_same_id_are_attributed_unambiguously(): void
    {
        [$tenant, $contact] = $this->tenantWithContact('audit-contact@example.test');
        $user = User::factory()->create();
        $portalUser = $this->createPortalUser($tenant, $contact, 'audit-portal@example.test');
        $this->assertSame($user->id, $portalUser->id, 'The fixture must exercise an ID collision.');

        $request = Request::create('/portal-audit-probe', 'POST');
        $this->app->instance('request', $request);
        app(TenantContext::class)->set($tenant->id);
        $this->actingAs($user);
        app(AuditService::class)->record(
            'staff.test',
            'portal_probe',
            'staff',
            request: $request,
            tenantId: $tenant->id,
        );

        $this->app['auth']->forgetGuards();
        $this->actingAs($portalUser);
        app(AuditService::class)->record(
            'portal.test',
            'portal_probe',
            'portal',
            request: $request,
            tenantId: $tenant->id,
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.test',
            'user_id' => $user->id,
            'portal_user_id' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal.test',
            'user_id' => null,
            'portal_user_id' => $portalUser->id,
        ]);
    }

    public function test_portal_actor_must_belong_to_the_audit_tenant(): void
    {
        [$auditTenant] = $this->tenantWithContact('audit-tenant@example.test');
        [$actorTenant, $actorContact] = $this->tenantWithContact('actor-tenant@example.test');
        $actor = $this->createPortalUser($actorTenant, $actorContact, 'foreign-actor@example.test');

        try {
            app(AuditService::class)->record(
                'portal.cross_tenant',
                'portal_probe',
                'forbidden',
                tenantId: $auditTenant->id,
                actor: $actor,
            );
            $this->fail('The audit service accepted a portal actor from another tenant.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The portal actor does not belong to the audit tenant.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'portal.cross_tenant']);
    }

    public function test_audit_redaction_recurses_and_is_case_insensitive(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create();

        $audit = app(AuditService::class)->record(
            'portal.redaction',
            'portal_probe',
            'redaction',
            oldValues: [
                'profile' => ['PASSWORD' => 'old-secret', 'display_name' => 'Visible'],
            ],
            newValues: [
                'password_confirmation' => 'confirmation-secret',
                'credentials' => ['api_key' => 'nested-secret'],
                'safe' => ['token_count' => 2],
            ],
            tenantId: $tenant->id,
            actor: $actor,
        );

        $this->assertSame('[REDACTED]', $audit->old_values['profile']['PASSWORD']);
        $this->assertSame('Visible', $audit->old_values['profile']['display_name']);
        $this->assertSame('[REDACTED]', $audit->new_values['password_confirmation']);
        $this->assertSame('[REDACTED]', $audit->new_values['credentials']);
        $this->assertSame(['token_count' => 2], $audit->new_values['safe']);
    }

    public function test_deleting_a_portal_user_nulls_the_audit_actor_without_deleting_the_log(): void
    {
        [$tenant, $contact] = $this->tenantWithContact('set-null@example.test');
        $portalUser = $this->createPortalUser($tenant, $contact, 'set-null-login@example.test');
        $audit = app(AuditService::class)->record(
            'portal.deleted_actor',
            'portal_users',
            $portalUser->id,
            tenantId: $tenant->id,
            actor: $portalUser,
        );

        $portalUser->delete();

        $this->assertDatabaseHas('audit_logs', [
            'id' => $audit->id,
            'user_id' => null,
            'portal_user_id' => null,
        ]);
    }

    public function test_portal_models_connect_the_expected_tenant_contact_actor_and_account_relations(): void
    {
        [$tenant, $contact] = $this->tenantWithContact('relations@example.test');
        app(TenantContext::class)->set($tenant->id);
        $inviter = User::factory()->create();
        $portal = CustomerPortal::create([
            'public_id' => '55555555-5555-4555-8555-555555555555',
            'title' => 'Relations portal',
        ]);
        $portalUser = $this->createPortalUser($tenant, $contact, 'relations-login@example.test');
        $invitation = PortalInvitation::create([
            'contact_id' => $contact->id,
            'invited_by' => $inviter->id,
            'email' => 'relations-invite@example.test',
            'token_hash' => str_repeat('f', 64),
            'expires_at' => now()->addDay(),
        ]);
        $reset = PortalPasswordResetToken::create([
            'portal_user_id' => $portalUser->id,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addHour(),
        ]);
        $audit = app(AuditService::class)->record(
            'portal.relations',
            $portalUser,
            actor: $portalUser,
        );

        $this->assertSame($tenant->id, $portal->tenant->id);
        $this->assertSame($tenant->id, $portalUser->tenant->id);
        $this->assertSame($contact->id, $portalUser->contact->id);
        $this->assertSame($tenant->id, $invitation->tenant->id);
        $this->assertSame($contact->id, $invitation->contact->id);
        $this->assertSame($inviter->id, $invitation->invitedBy->id);
        $this->assertSame($tenant->id, $reset->tenant->id);
        $this->assertSame($portalUser->id, $reset->portalUser->id);
        $this->assertSame($portal->id, $tenant->customerPortal->id);
        $this->assertSame($portalUser->id, $tenant->portalUsers->sole()->id);
        $this->assertSame($portalUser->id, $contact->portalUser->id);
        $this->assertSame($invitation->id, $contact->portalInvitations->sole()->id);
        $this->assertSame($portalUser->id, $audit->portalUser->id);
    }

    public function test_portal_credentials_are_hidden_and_dates_use_immutable_casts(): void
    {
        [$tenant, $contact] = $this->tenantWithContact('hidden@example.test');
        $portalUser = PortalUser::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'email' => 'hidden-login@example.test',
            'password' => 'plain-password',
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);
        $invitation = PortalInvitation::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'email' => 'hidden-invite@example.test',
            'token_hash' => str_repeat('1', 64),
            'expires_at' => now()->addDay(),
            'accepted_at' => now(),
        ]);
        $reset = PortalPasswordResetToken::create([
            'tenant_id' => $tenant->id,
            'portal_user_id' => $portalUser->id,
            'token_hash' => str_repeat('2', 64),
            'expires_at' => now()->addHour(),
            'used_at' => now(),
        ]);
        $portalUser->refresh();
        $invitation->refresh();

        $this->assertSame('ACTIVE', PortalUser::STATUS_ACTIVE);
        $this->assertSame('SUSPENDED', PortalUser::STATUS_SUSPENDED);
        $this->assertSame('ACTIVE', $portalUser->status);
        $this->assertSame('PENDING', $invitation->status);
        $this->assertTrue(Hash::check('plain-password', $portalUser->getRawOriginal('password')));
        $this->assertArrayNotHasKey('password', $portalUser->toArray());
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        $this->assertArrayNotHasKey('token_hash', $reset->toArray());
        $this->assertInstanceOf(CarbonImmutable::class, $portalUser->email_verified_at);
        $this->assertInstanceOf(CarbonImmutable::class, $portalUser->last_login_at);
        $this->assertInstanceOf(CarbonImmutable::class, $invitation->expires_at);
        $this->assertInstanceOf(CarbonImmutable::class, $invitation->accepted_at);
        $this->assertInstanceOf(CarbonImmutable::class, $reset->expires_at);
        $this->assertInstanceOf(CarbonImmutable::class, $reset->used_at);
    }

    public function test_portal_user_has_an_explicit_morph_alias(): void
    {
        [$tenant, $contact] = $this->tenantWithContact('morph@example.test');
        $portalUser = $this->createPortalUser($tenant, $contact, 'morph-login@example.test');

        $this->assertSame(PortalUser::class, Relation::getMorphedModel('portal_user'));
        $this->assertSame('portal_user', $portalUser->getMorphClass());
    }

    public function test_migration_grants_portal_permission_only_to_system_and_settings_manager_roles(): void
    {
        $manager = $this->createTenantUser(['settings.manage']);
        $custom = $this->createTenantUser(['contacts.view']);
        $system = Role::create([
            'tenant_id' => $manager['tenant']->id,
            'name' => 'System',
            'is_system' => true,
        ]);
        $migration = require database_path(
            'migrations/2026_09_20_000000_create_customer_portal_foundations.php',
        );

        $migration->down();
        $this->assertSame(0, Permission::where('key', 'portal.manage')->count());
        $migration->up();

        $this->assertSame(
            1,
            $system->permissions()->where('key', 'portal.manage')->count(),
        );
        $this->assertSame(
            1,
            $manager['user']->memberships()->firstOrFail()->role
                ->permissions()->where('key', 'portal.manage')->count(),
        );
        $this->assertSame(
            0,
            $custom['user']->memberships()->firstOrFail()->role
                ->permissions()->where('key', 'portal.manage')->count(),
        );
        $this->assertTrue(
            $custom['user']->hasPermission('contacts.view', $custom['tenant']->id),
        );
    }

    public function test_portal_migration_rolls_back_and_reapplies_in_the_same_memory_database(): void
    {
        $tables = [
            'customer_portals',
            'portal_users',
            'portal_invitations',
            'portal_password_reset_tokens',
            'customer_portal_locators',
            'portal_invitation_locators',
        ];

        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(
            0,
            Artisan::call('migrate', ['--force' => true]),
            Artisan::output(),
        );
        $this->assertSame(
            0,
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]),
            Artisan::output(),
        );

        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} survived rollback.");
        }
        $this->assertFalse(Schema::hasColumn('audit_logs', 'portal_user_id'));

        $this->assertSame(
            0,
            Artisan::call('migrate', ['--force' => true]),
            Artisan::output(),
        );

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} was not reapplied.");
        }
        $this->assertTrue(Schema::hasColumn('audit_logs', 'portal_user_id'));
    }

    /** @return array{Tenant, Contact} */
    private function tenantWithContact(string $email): array
    {
        $tenant = Tenant::factory()->create();
        $contact = Contact::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
        ]);

        return [$tenant, $contact];
    }

    private function createPortalUser(Tenant $tenant, Contact $contact, string $email): PortalUser
    {
        return PortalUser::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'email' => $email,
            'password' => 'portal-password',
        ]);
    }

    private function assertConstraintRejected(callable $operation, string $message): void
    {
        try {
            DB::transaction($operation);
            $this->fail($message);
        } catch (QueryException $exception) {
            $this->assertContains((string) $exception->getCode(), ['23000', '23503', '23505']);
        }
    }
}
