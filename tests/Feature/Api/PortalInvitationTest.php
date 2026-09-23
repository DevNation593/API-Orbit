<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\PortalInvitation;
use App\Models\PortalUser;
use App\Notifications\PortalInvitationNotification;
use App\Services\PortalInvitationService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PortalTestCase;

class PortalInvitationTest extends PortalTestCase
{
    use RefreshDatabase;

    public function test_manager_invites_contact_and_plain_token_is_only_returned_and_notified(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-09-21 14:00:00');

        try {
            $fixture = $this->portalFixture(['portal.manage']);
            $fixture['contact']->update([
                'first_name' => 'Amy',
                'last_name' => 'Example',
                'email' => '  Amy@Example.TEST  ',
            ]);

            $response = $this->internalApi($fixture)
                ->postJson('/api/v1/customer-portal/invitations', [
                    'contact_id' => $fixture['contact']->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.contact_id', $fixture['contact']->id)
                ->assertJsonPath('data.email', 'amy@example.test')
                ->assertJsonPath('data.status', 'PENDING')
                ->assertJsonPath('meta.notification_sent', true);

            $activationUrl = $response->json('meta.activation_url');
            $this->assertIsString($activationUrl);
            $token = Str::afterLast($activationUrl, '/');
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
            $tokenHash = hash('sha256', $token);

            $this->assertDatabaseMissing('portal_invitations', ['token_hash' => $token]);
            $this->assertDatabaseHas('portal_invitations', [
                'tenant_id' => $fixture['tenant']->id,
                'contact_id' => $fixture['contact']->id,
                'email' => 'amy@example.test',
                'token_hash' => $tokenHash,
                'status' => 'PENDING',
                'expires_at' => '2026-09-28 14:00:00',
            ]);
            $invitation = PortalInvitation::withoutGlobalScopes()->sole();
            $this->assertDatabaseHas('portal_invitation_locators', [
                'token_hash' => $tokenHash,
                'invitation_id' => $invitation->id,
                'tenant_id' => $fixture['tenant']->id,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $fixture['tenant']->id,
                'user_id' => $fixture['user']->id,
                'portal_user_id' => null,
                'action' => 'portal.user.invited',
            ]);
            $this->assertDatabaseCount('notifications', 0);

            Notification::assertSentOnDemand(
                PortalInvitationNotification::class,
                function ($notification, array $channels, $notifiable) use (
                    $activationUrl,
                ): bool {
                    return $channels === ['mail']
                        && $notifiable->routes['mail'] === 'amy@example.test'
                        && $notification->portalTitle === 'Portal de clientes'
                        && $notification->contactName === 'Amy Example'
                        && $notification->activationUrl === $activationUrl;
                },
            );

            $responseData = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
            $auditData = json_encode(
                AuditLog::withoutGlobalScopes()->get(['old_values', 'new_values'])->toArray(),
                JSON_THROW_ON_ERROR,
            );
            foreach ([$token, $tokenHash, $activationUrl, 'Portal-Password!2026'] as $secret) {
                $this->assertStringNotContainsString($secret, $responseData);
                $this->assertStringNotContainsString($secret, $auditData);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_notification_failure_happens_after_commit_and_does_not_rollback_invitation(): void
    {
        Notification::fake();
        $fixture = $this->portalFixture(['portal.manage']);
        $baseTransactionLevel = DB::transactionLevel();
        $dispatcher = new class($baseTransactionLevel) implements Dispatcher
        {
            public bool $observedCommittedInvitation = false;

            public function __construct(private readonly int $baseTransactionLevel) {}

            public function send($notifiables, $notification): void
            {
                $this->observedCommittedInvitation = DB::transactionLevel() === $this->baseTransactionLevel
                    && DB::table('portal_invitations')->where('status', 'PENDING')->exists()
                    && DB::table('portal_invitation_locators')->exists()
                    && DB::table('audit_logs')->where('action', 'portal.user.invited')->exists();

                throw new RuntimeException('Simulated mail transport failure.');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                $this->send($notifiables, $notification);
            }
        };
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->internalApi($fixture)
            ->postJson('/api/v1/customer-portal/invitations', [
                'contact_id' => $fixture['contact']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.notification_sent', false);

        $this->assertTrue($dispatcher->observedCommittedInvitation);
        $this->assertDatabaseCount('portal_invitations', 1);
        $this->assertDatabaseCount('portal_invitation_locators', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal.user.invited']);
    }

    public function test_invite_rejects_an_ambient_transaction_before_token_generation_or_mutation(): void
    {
        Notification::fake();
        $fixture = $this->portalFixture(['portal.manage']);
        $baseTransactionLevel = DB::transactionLevel();
        $tokenGenerated = false;
        $caught = null;
        $auditCount = DB::table('audit_logs')->count();
        Str::createRandomStringsUsing(function (int $length) use (&$tokenGenerated): string {
            $tokenGenerated = true;

            return str_repeat('R', $length);
        });
        DB::beginTransaction();

        try {
            try {
                app(PortalInvitationService::class)->invite(
                    $fixture['contact'],
                    $fixture['user'],
                );
            } catch (LogicException $exception) {
                $caught = $exception;
            }

            $this->assertInstanceOf(LogicException::class, $caught);
            $this->assertFalse($tokenGenerated);
            $this->assertDatabaseCount('portal_invitations', 0);
            $this->assertDatabaseCount('portal_invitation_locators', 0);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            Notification::assertNothingSent();
        } finally {
            while (DB::transactionLevel() > $baseTransactionLevel) {
                DB::rollBack();
            }
            Str::createRandomStringsNormally();
        }
    }

    #[DataProvider('forbiddenInvitationRootKeys')]
    public function test_invitation_creation_rejects_every_extra_root_key_by_presence(
        string $field,
        mixed $value,
    ): void {
        Notification::fake();
        $fixture = $this->portalFixture(['portal.manage']);

        $this->internalApi($fixture)
            ->postJson('/api/v1/customer-portal/invitations', [
                'contact_id' => $fixture['contact']->id,
                $field => $value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('portal_invitations', 0);
        $this->assertDatabaseCount('portal_invitation_locators', 0);
        Notification::assertNothingSent();
    }

    public function test_invitation_rejects_missing_invalid_deleted_and_foreign_contacts_without_leaking(): void
    {
        Notification::fake();
        $foreign = $this->portalFixture(['portal.manage']);
        $foreignContactId = $foreign['contact']->id;
        $this->app['auth']->forgetGuards();
        $local = $this->portalFixture(['portal.manage']);
        $api = $this->internalApi($local);

        $api->postJson('/api/v1/customer-portal/invitations', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
        $api->postJson('/api/v1/customer-portal/invitations', ['contact_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
        $foreignResponse = $api->postJson('/api/v1/customer-portal/invitations', [
            'contact_id' => $foreignContactId,
        ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
        $this->assertStringNotContainsString(
            (string) $foreign['tenant']->id,
            json_encode($foreignResponse->json(), JSON_THROW_ON_ERROR),
        );

        $local['contact']->delete();
        $api->postJson('/api/v1/customer-portal/invitations', [
            'contact_id' => $local['contact']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

        $this->assertDatabaseCount('portal_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_invitation_requires_a_valid_normalizable_contact_email(): void
    {
        Notification::fake();
        $fixture = $this->portalFixture(['portal.manage']);

        foreach ([null, '', 'not-an-email'] as $email) {
            $fixture['contact']->update(['email' => $email]);
            $this->internalApi($fixture)
                ->postJson('/api/v1/customer-portal/invitations', [
                    'contact_id' => $fixture['contact']->id,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['contact_id']);
        }

        $this->assertDatabaseCount('portal_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_invitation_requires_an_active_configured_portal(): void
    {
        Notification::fake();
        $withoutPortal = $this->createTenantUser(['portal.manage']);
        $contact = Contact::factory()->create([
            'tenant_id' => $withoutPortal['tenant']->id,
            'email' => 'without-portal@example.test',
        ]);
        $this->internalApi($withoutPortal)
            ->postJson('/api/v1/customer-portal/invitations', ['contact_id' => $contact->id])
            ->assertConflict()
            ->assertJsonPath('message', 'Activate the customer portal before inviting contacts.');

        $this->app['auth']->forgetGuards();
        $inactive = $this->portalFixture(['portal.manage'], false);
        $this->internalApi($inactive)
            ->postJson('/api/v1/customer-portal/invitations', [
                'contact_id' => $inactive['contact']->id,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Activate the customer portal before inviting contacts.');

        $this->assertDatabaseCount('portal_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_invitation_rejects_existing_account_by_contact_or_normalized_email(): void
    {
        Notification::fake();
        $byContact = $this->portalFixture(['portal.manage']);
        $this->createPortalUser($byContact);
        $this->internalApi($byContact)
            ->postJson('/api/v1/customer-portal/invitations', [
                'contact_id' => $byContact['contact']->id,
            ])->assertConflict();

        $this->app['auth']->forgetGuards();
        $byEmail = $this->portalFixture(['portal.manage']);
        $otherContact = Contact::factory()->create([
            'tenant_id' => $byEmail['tenant']->id,
            'email' => 'other@example.test',
        ]);
        PortalUser::create([
            'contact_id' => $otherContact->id,
            'email' => mb_strtolower((string) $byEmail['contact']->email),
            'password' => 'Portal-Password!2026',
            'status' => 'ACTIVE',
        ]);
        $this->internalApi($byEmail)
            ->postJson('/api/v1/customer-portal/invitations', [
                'contact_id' => $byEmail['contact']->id,
            ])->assertConflict();

        $this->assertDatabaseCount('portal_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_reinvite_atomically_revokes_prior_pending_invitation(): void
    {
        Notification::fake();
        $fixture = $this->portalFixture(['portal.manage']);
        $api = $this->internalApi($fixture);

        $first = $api->postJson('/api/v1/customer-portal/invitations', [
            'contact_id' => $fixture['contact']->id,
        ])->assertCreated();
        $firstToken = Str::afterLast((string) $first->json('meta.activation_url'), '/');
        $second = $api->postJson('/api/v1/customer-portal/invitations', [
            'contact_id' => $fixture['contact']->id,
        ])->assertCreated();
        $secondToken = Str::afterLast((string) $second->json('meta.activation_url'), '/');

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertDatabaseHas('portal_invitations', [
            'token_hash' => hash('sha256', $firstToken),
            'status' => 'REVOKED',
        ]);
        $this->assertDatabaseHas('portal_invitations', [
            'token_hash' => hash('sha256', $secondToken),
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseCount('portal_invitation_locators', 2);
        $this->assertDatabaseCount('portal_invitations', 2);
        $this->assertSame(
            2,
            AuditLog::withoutGlobalScopes()->where('action', 'portal.user.invited')->count(),
        );
        Notification::assertSentOnDemandTimes(PortalInvitationNotification::class, 2);
        $this->getJson('/api/v1/portal/invitations/'.$firstToken)->assertConflict();
        $this->getJson('/api/v1/portal/invitations/'.$secondToken)->assertOk();
    }

    public function test_invite_and_accept_query_the_portal_before_downstream_locked_rows(): void
    {
        Notification::fake();
        $fixture = $this->portalFixture(['portal.manage']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });
        $service = app(PortalInvitationService::class);

        $invited = $service->invite($fixture['contact'], $fixture['user']);
        $inviteQueries = $queries;
        $queries = [];
        $token = Str::afterLast($invited['activation_url'], '/');
        $service->accept($token, [
            'password' => 'Portal-Password!2026',
            'password_confirmation' => 'Portal-Password!2026',
        ]);
        $acceptQueries = $queries;

        $invitePortal = collect($inviteQueries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "customer_portals"'),
        );
        $inviteContact = collect($inviteQueries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "contacts"'),
        );
        $inviteInvitation = collect($inviteQueries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "portal_invitations"'),
        );
        $acceptPortal = collect($acceptQueries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "customer_portals"'),
        );
        $acceptInvitation = collect($acceptQueries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "portal_invitations"'),
        );
        $acceptContact = collect($acceptQueries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "contacts"'),
        );

        foreach ([
            $invitePortal,
            $inviteContact,
            $inviteInvitation,
            $acceptPortal,
            $acceptInvitation,
            $acceptContact,
        ] as $index) {
            $this->assertIsInt($index);
        }
        $this->assertLessThan($inviteContact, $invitePortal);
        $this->assertLessThan($inviteInvitation, $invitePortal);
        $this->assertLessThan($acceptInvitation, $acceptPortal);
        $this->assertLessThan($acceptContact, $acceptPortal);
    }

    public function test_internal_list_filters_sorts_and_paginates_without_serializing_secrets(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $secondContact = Contact::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'first_name' => 'Second',
            'last_name' => 'Contact',
            'email' => 'second@example.test',
        ]);
        $thirdContact = Contact::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'first_name' => 'Third',
            'last_name' => 'Contact',
            'email' => 'third@example.test',
        ]);
        $fourthContact = Contact::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'first_name' => 'Fourth',
            'last_name' => 'Contact',
            'email' => 'fourth@example.test',
        ]);
        $first = $this->storedInvitation($fixture, $fixture['contact'], [
            'created_at' => '2026-09-01 10:00:00',
            'expires_at' => '2026-09-30 10:00:00',
        ], str_repeat('a', 64));
        $second = $this->storedInvitation($fixture, $secondContact, [
            'status' => 'REVOKED',
            'created_at' => '2026-09-10 10:00:00',
            'expires_at' => '2026-09-20 10:00:00',
        ], str_repeat('b', 64));
        $third = $this->storedInvitation($fixture, $thirdContact, [
            'status' => 'ACCEPTED',
            'accepted_at' => '2026-09-15 12:00:00',
            'created_at' => '2026-09-15 10:00:00',
            'expires_at' => '2026-10-01 10:00:00',
        ], str_repeat('c', 64));
        $fourth = $this->storedInvitation($fixture, $fourthContact, [
            'status' => 'REVOKED',
            'created_at' => '2026-09-12 10:00:00',
            'expires_at' => '2026-09-22 10:00:00',
        ], str_repeat('d', 64));
        $api = $this->internalApi($fixture);

        $api->getJson('/api/v1/customer-portal/invitations?status=REVOKED&contact_id='.$secondContact->id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second['invitation']->id)
            ->assertJsonPath('data.0.contact.first_name', 'Second');

        $page = $api->getJson(
            '/api/v1/customer-portal/invitations'
            .'?created_from=2026-09-09&created_to=2026-09-13'
            .'&expires_before=2026-09-25&sort=expires_at&direction=desc&per_page=1&page=2',
        )->assertOk()
            ->assertJsonPath('data.0.id', $second['invitation']->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);

        $serialized = json_encode($page->json(), JSON_THROW_ON_ERROR);
        foreach ([$first['token'], $second['token'], $third['token'], $fourth['token']] as $token) {
            $this->assertStringNotContainsString($token, $serialized);
            $this->assertStringNotContainsString(hash('sha256', $token), $serialized);
        }
        $this->assertStringNotContainsString('token_hash', $serialized);
    }

    public function test_internal_list_rejects_invalid_filters(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $api = $this->internalApi($fixture);

        foreach ([
            'status=UNKNOWN' => 'status',
            'contact_id=0' => 'contact_id',
            'created_from=not-a-date' => 'created_from',
            'created_to=not-a-date' => 'created_to',
            'expires_before=not-a-date' => 'expires_before',
            'sort=email' => 'sort',
            'direction=sideways' => 'direction',
            'page=0' => 'page',
            'per_page=101' => 'per_page',
        ] as $query => $field) {
            $api->getJson('/api/v1/customer-portal/invitations?'.$query)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    #[DataProvider('unknownInvitationListValues')]
    public function test_internal_list_rejects_every_unknown_query_key_by_presence(
        string $query,
    ): void {
        $fixture = $this->portalFixture(['portal.manage']);

        $this->internalApi($fixture)
            ->getJson('/api/v1/customer-portal/invitations?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unexpected']);
    }

    public function test_internal_list_pagination_appends_only_validated_filters(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $secondContact = Contact::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'email' => 'second-pending@example.test',
        ]);
        $this->storedInvitation($fixture);
        $this->storedInvitation($fixture, $secondContact);
        Paginator::queryStringResolver(function (): never {
            throw new RuntimeException('Raw request query strings must not be appended.');
        });

        try {
            $this->internalApi($fixture)
                ->getJson(
                    '/api/v1/customer-portal/invitations'
                    .'?status=PENDING&sort=created_at&direction=asc&per_page=1&page=1',
                )
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.per_page', 1)
                ->assertJsonPath('meta.total', 2);
        } finally {
            Paginator::queryStringResolver(fn (): array => app('request')->query());
        }
    }

    public function test_revoke_is_idempotent_for_revoked_but_conflicts_for_accepted_and_is_tenant_safe(): void
    {
        $foreign = $this->portalFixture(['portal.manage']);
        $foreignInvitation = $this->storedInvitation($foreign);
        $this->app['auth']->forgetGuards();
        $local = $this->portalFixture(['portal.manage']);
        $pending = $this->storedInvitation($local);
        $accepted = $this->storedInvitation($local, null, [
            'status' => 'ACCEPTED',
            'accepted_at' => now(),
        ]);
        $api = $this->internalApi($local);

        $api->deleteJson('/api/v1/customer-portal/invitations/'.$pending['invitation']->id)
            ->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $api->deleteJson('/api/v1/customer-portal/invitations/'.$pending['invitation']->id)
            ->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->assertSame(
            1,
            AuditLog::withoutGlobalScopes()
                ->where('action', 'portal.invitation.revoked')
                ->where('entity_id', (string) $pending['invitation']->id)
                ->count(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal.invitation.revoked',
            'user_id' => $local['user']->id,
            'portal_user_id' => null,
        ]);

        $api->deleteJson('/api/v1/customer-portal/invitations/'.$accepted['invitation']->id)
            ->assertConflict();
        $api->deleteJson('/api/v1/customer-portal/invitations/'.$foreignInvitation['invitation']->id)
            ->assertNotFound();
        $api->deleteJson('/api/v1/customer-portal/invitations/999999')
            ->assertNotFound();

        $this->assertDatabaseHas('portal_invitations', [
            'id' => $foreignInvitation['invitation']->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_public_inspection_returns_only_safe_profile_fields_and_masks_email(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $fixture['contact']->update([
            'first_name' => 'Amy',
            'last_name' => 'Example',
            'email' => 'amy@example.test',
        ]);
        $stored = $this->storedInvitation($fixture);

        $response = $this->getJson('/api/v1/portal/invitations/'.$stored['token'])
            ->assertOk()
            ->assertJsonPath('data.portal_public_id', $fixture['portal']->public_id)
            ->assertJsonPath('data.portal_title', 'Portal de clientes')
            ->assertJsonPath('data.email', 'a***@example.test')
            ->assertJsonPath('data.contact_name', 'Amy Example')
            ->assertJsonStructure(['data' => [
                'portal_public_id', 'portal_title', 'email', 'contact_name', 'expires_at',
            ]]);

        $this->assertSame(
            ['portal_public_id', 'portal_title', 'email', 'contact_name', 'expires_at'],
            array_keys($response->json('data')),
        );
        $this->assertExternalPayloadHasNoInternalIds($response->json('data'));
        $this->assertStringNotContainsString('Bearer', $response->getContent());

        $this->getJson('/api/v1/portal/invitations/not-valid')->assertNotFound();
        $this->getJson('/api/v1/portal/invitations/'.str_repeat('z', 64))->assertNotFound();
    }

    #[DataProvider('invalidInspectionContactStates')]
    public function test_public_inspection_rejects_an_invalid_current_contact_without_exposing_stale_data(
        string $state,
    ): void {
        $fixture = $this->portalFixture(['portal.manage']);
        $fixture['contact']->update([
            'first_name' => 'Sensitive',
            'last_name' => 'Contact',
            'email' => 'snapshot@example.test',
        ]);
        $stored = $this->storedInvitation($fixture);

        if ($state === 'deleted') {
            $fixture['contact']->delete();
        } else {
            $fixture['contact']->update(['email' => 'changed@example.test']);
        }

        $response = $this->getJson('/api/v1/portal/invitations/'.$stored['token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_id']);

        $serialized = $response->getContent();
        foreach (['Sensitive Contact', 'snapshot@example.test', 's*******@example.test'] as $stale) {
            $this->assertStringNotContainsString($stale, $serialized);
        }
        $this->assertDatabaseHas('portal_invitations', [
            'id' => $stored['invitation']->id,
            'status' => 'PENDING',
        ]);
    }

    #[DataProvider('unusableInvitationStatuses')]
    public function test_accepted_and_revoked_tokens_are_conflicts_for_inspection_and_acceptance(
        string $status,
    ): void {
        $fixture = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($fixture, null, [
            'status' => $status,
            'accepted_at' => $status === 'ACCEPTED' ? now() : null,
        ]);

        $this->getJson('/api/v1/portal/invitations/'.$stored['token'])
            ->assertConflict();
        $this->postJson('/api/v1/portal/invitations/'.$stored['token'].'/accept', [
            'password' => 'Portal-Password!2026',
            'password_confirmation' => 'Portal-Password!2026',
        ])->assertConflict();
        $this->assertDatabaseCount('portal_users', 0);
    }

    public function test_authentic_expired_token_returns_gone_and_is_persistently_marked_expired(): void
    {
        CarbonImmutable::setTestNow('2026-09-21 14:00:00');

        try {
            $fixture = $this->portalFixture(['portal.manage']);
            $stored = $this->storedInvitation($fixture, null, [
                'expires_at' => '2026-09-21 13:59:59',
            ]);

            $this->getJson('/api/v1/portal/invitations/'.$stored['token'])->assertGone();
            $this->assertDatabaseHas('portal_invitations', [
                'id' => $stored['invitation']->id,
                'status' => 'EXPIRED',
            ]);
            $this->postJson('/api/v1/portal/invitations/'.$stored['token'].'/accept', [
                'password' => 'Portal-Password!2026',
                'password_confirmation' => 'Portal-Password!2026',
            ])->assertGone();
            $this->assertDatabaseCount('portal_users', 0);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_locator_lookup_precedes_tenant_models_and_restores_prior_context(): void
    {
        $local = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($local);
        $foreign = $this->portalFixture(['portal.manage']);
        $context = app(TenantContext::class);
        $context->set((int) $foreign['tenant']->id);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = mb_strtolower($query->sql);
            if (str_contains($sql, 'portal_invitation')) {
                $queries[] = $sql;
            }
        });

        $this->getJson('/api/v1/portal/invitations/'.$stored['token'])->assertOk();

        $this->assertSame((int) $foreign['tenant']->id, $context->id());
        $locatorIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'portal_invitation_locators'),
        );
        $invitationIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'portal_invitations')
                && ! str_contains($sql, 'portal_invitation_locators'),
        );
        $this->assertIsInt($locatorIndex);
        $this->assertIsInt($invitationIndex);
        $this->assertLessThan($invitationIndex, $locatorIndex);

        DB::table('portal_invitation_locators')
            ->where('token_hash', hash('sha256', $stored['token']))
            ->delete();
        $inconsistentToken = str_repeat('i', 64);
        DB::table('portal_invitation_locators')->insert([
            'token_hash' => hash('sha256', $inconsistentToken),
            'invitation_id' => $stored['invitation']->id,
            'tenant_id' => $local['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/portal/invitations/'.$inconsistentToken)->assertNotFound();
        $this->assertSame((int) $foreign['tenant']->id, $context->id());
    }

    public function test_orphaned_locator_is_not_found_and_restores_context(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $context = app(TenantContext::class);
        $context->set((int) $fixture['tenant']->id);
        $token = str_repeat('o', 64);
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('portal_invitation_locators')->insert([
            'token_hash' => hash('sha256', $token),
            'invitation_id' => 999999,
            'tenant_id' => $fixture['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/portal/invitations/'.$token)->assertNotFound();
        $this->assertSame((int) $fixture['tenant']->id, $context->id());
    }

    public function test_acceptance_creates_one_verified_account_audits_portal_actor_and_issues_portal_session(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-09-21 14:00:00');

        try {
            $fixture = $this->portalFixture(['portal.manage']);
            $fixture['contact']->update(['email' => '  Session.User@Example.TEST  ']);
            $invite = $this->internalApi($fixture)
                ->postJson('/api/v1/customer-portal/invitations', [
                    'contact_id' => $fixture['contact']->id,
                ])->assertCreated();
            $invitationToken = Str::afterLast(
                (string) $invite->json('meta.activation_url'),
                '/',
            );
            $baseTransactionLevel = DB::transactionLevel();
            $tokenTransactionLevel = null;
            PersonalAccessToken::creating(function () use (&$tokenTransactionLevel): void {
                $tokenTransactionLevel = DB::transactionLevel();
            });

            $response = $this->postJson(
                '/api/v1/portal/invitations/'.$invitationToken.'/accept',
                [
                    'password' => 'Portal-Password!2026',
                    'password_confirmation' => 'Portal-Password!2026',
                    'device_name' => 'Customer laptop',
                ],
            )->assertCreated()
                ->assertJsonPath('data.token_type', 'Bearer')
                ->assertJsonPath('data.expires_at', '2026-10-21T14:00:00.000000Z')
                ->assertJsonPath('data.profile.portal_public_id', $fixture['portal']->public_id)
                ->assertJsonPath('data.profile.portal_title', 'Portal de clientes')
                ->assertJsonPath('data.profile.email', 'session.user@example.test')
                ->assertJsonPath('data.profile.contact_name', 'Cliente Principal');

            $accessToken = $response->json('data.access_token');
            $this->assertIsString($accessToken);
            $this->assertNotSame('', $accessToken);
            $this->assertSame($baseTransactionLevel, $tokenTransactionLevel);
            $this->assertDatabaseCount('portal_users', 1);
            $portalUser = PortalUser::withoutGlobalScopes()->sole();
            $this->assertSame('ACTIVE', $portalUser->status);
            $this->assertSame('session.user@example.test', $portalUser->email);
            $this->assertNotNull($portalUser->email_verified_at);
            $this->assertTrue(Hash::check(
                'Portal-Password!2026',
                (string) $portalUser->getRawOriginal('password'),
            ));
            $this->assertDatabaseHas('portal_invitations', [
                'token_hash' => hash('sha256', $invitationToken),
                'status' => 'ACCEPTED',
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'portal.invitation.accepted',
                'user_id' => null,
                'portal_user_id' => $portalUser->id,
            ]);

            $personalToken = PersonalAccessToken::findToken($accessToken);
            $this->assertNotNull($personalToken);
            $this->assertSame('Customer laptop', $personalToken->name);
            $this->assertSame(['portal'], $personalToken->abilities);
            $this->assertSame('2026-10-21 14:00:00', $personalToken->expires_at?->format('Y-m-d H:i:s'));
            $this->assertTrue($personalToken->can('portal'));
            $this->assertFalse($personalToken->can('portal.manage'));
            $this->assertSame($portalUser->id, $personalToken->tokenable_id);
            $this->assertSame('portal_user', $personalToken->tokenable_type);
            $this->assertExternalPayloadHasNoInternalIds($response->json('data.profile'));

            $safeSession = $response->json('data');
            unset($safeSession['access_token']);
            $serializedSession = json_encode($safeSession, JSON_THROW_ON_ERROR);
            $serializedAudits = json_encode(
                AuditLog::withoutGlobalScopes()->get(['old_values', 'new_values'])->toArray(),
                JSON_THROW_ON_ERROR,
            );
            foreach ([
                $invitationToken,
                hash('sha256', $invitationToken),
                'Portal-Password!2026',
                (string) $invite->json('meta.activation_url'),
            ] as $secret) {
                $this->assertStringNotContainsString($secret, $serializedSession);
                $this->assertStringNotContainsString($secret, $serializedAudits);
            }

            $this->postJson('/api/v1/portal/invitations/'.$invitationToken.'/accept', [
                'password' => 'Different-Password!2026',
                'password_confirmation' => 'Different-Password!2026',
            ])->assertConflict();
            $this->assertDatabaseCount('portal_users', 1);
            $this->assertDatabaseCount('personal_access_tokens', 2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_accept_rejects_an_ambient_transaction_without_mutating_domain_or_session_state(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($fixture);
        $baseTransactionLevel = DB::transactionLevel();
        $sessionTokenAttempted = false;
        $caught = null;
        $invitationBefore = (array) DB::table('portal_invitations')
            ->where('id', $stored['invitation']->id)
            ->first();
        $contactBefore = (array) DB::table('contacts')
            ->where('id', $fixture['contact']->id)
            ->first();
        $auditCount = DB::table('audit_logs')->count();
        $sanctumTokenCount = DB::table('personal_access_tokens')->count();
        PersonalAccessToken::creating(function () use (&$sessionTokenAttempted): void {
            $sessionTokenAttempted = true;
        });
        DB::beginTransaction();

        try {
            try {
                app(PortalInvitationService::class)->accept($stored['token'], [
                    'password' => 'Portal-Password!2026',
                    'password_confirmation' => 'Portal-Password!2026',
                ]);
            } catch (LogicException $exception) {
                $caught = $exception;
            }

            $this->assertInstanceOf(LogicException::class, $caught);
            $this->assertFalse($sessionTokenAttempted);
            $this->assertDatabaseCount('portal_users', 0);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertSame($sanctumTokenCount, DB::table('personal_access_tokens')->count());
            $this->assertSame(
                $invitationBefore,
                (array) DB::table('portal_invitations')
                    ->where('id', $stored['invitation']->id)
                    ->first(),
            );
            $this->assertSame(
                $contactBefore,
                (array) DB::table('contacts')->where('id', $fixture['contact']->id)->first(),
            );
        } finally {
            while (DB::transactionLevel() > $baseTransactionLevel) {
                DB::rollBack();
            }
        }
    }

    #[DataProvider('forbiddenAcceptanceRootKeys')]
    public function test_acceptance_rejects_every_extra_root_key_by_presence(
        string $field,
        mixed $value,
    ): void {
        $fixture = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($fixture);

        $this->postJson('/api/v1/portal/invitations/'.$stored['token'].'/accept', [
            'password' => 'Portal-Password!2026',
            'password_confirmation' => 'Portal-Password!2026',
            $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('portal_users', 0);
        $this->assertDatabaseHas('portal_invitations', [
            'id' => $stored['invitation']->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_acceptance_validates_password_confirmation_and_device_name(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($fixture);
        $url = '/api/v1/portal/invitations/'.$stored['token'].'/accept';

        $this->postJson($url, [
            'password' => 'Portal-Password!2026',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->postJson($url, [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->postJson($url, [
            'password' => 'Portal-Password!2026',
            'password_confirmation' => 'Portal-Password!2026',
            'device_name' => str_repeat('x', 121),
        ])->assertUnprocessable()->assertJsonValidationErrors(['device_name']);

        $this->assertDatabaseCount('portal_users', 0);
    }

    #[DataProvider('secretDeviceNames')]
    public function test_acceptance_rejects_a_device_name_containing_the_current_invitation_token(
        bool $exactMatch,
    ): void {
        $fixture = $this->portalFixture(['portal.manage']);
        $token = str_repeat('T', 64);
        $stored = $this->storedInvitation($fixture, token: $token);
        $deviceName = $exactMatch ? $token : 'Laptop-'.$token.'-browser';
        $invitationBefore = (array) DB::table('portal_invitations')
            ->where('id', $stored['invitation']->id)
            ->first();
        $contactBefore = (array) DB::table('contacts')
            ->where('id', $fixture['contact']->id)
            ->first();
        $auditCount = DB::table('audit_logs')->count();
        $sanctumTokenCount = DB::table('personal_access_tokens')->count();

        $this->postJson('/api/v1/portal/invitations/'.$token.'/accept', [
            'password' => 'Portal-Password!2026',
            'password_confirmation' => 'Portal-Password!2026',
            'device_name' => $deviceName,
        ])->assertUnprocessable()->assertJsonValidationErrors(['device_name']);

        $this->assertDatabaseCount('portal_users', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($sanctumTokenCount, DB::table('personal_access_tokens')->count());
        $this->assertSame(
            $invitationBefore,
            (array) DB::table('portal_invitations')
                ->where('id', $stored['invitation']->id)
                ->first(),
        );
        $this->assertSame(
            $contactBefore,
            (array) DB::table('contacts')->where('id', $fixture['contact']->id)->first(),
        );
        $this->assertDatabaseDoesNotContainPlaintext($token);
    }

    public function test_acceptance_revalidates_active_portal_contact_and_current_normalized_email(): void
    {
        $password = [
            'password' => 'Portal-Password!2026',
            'password_confirmation' => 'Portal-Password!2026',
        ];

        $changed = $this->portalFixture(['portal.manage']);
        $changedInvitation = $this->storedInvitation($changed);
        $changed['contact']->update(['email' => 'changed@example.test']);
        $this->postJson(
            '/api/v1/portal/invitations/'.$changedInvitation['token'].'/accept',
            $password,
        )->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

        $this->app['auth']->forgetGuards();
        $deleted = $this->portalFixture(['portal.manage']);
        $deletedInvitation = $this->storedInvitation($deleted);
        $deleted['contact']->delete();
        $this->postJson(
            '/api/v1/portal/invitations/'.$deletedInvitation['token'].'/accept',
            $password,
        )->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

        $this->app['auth']->forgetGuards();
        $inactive = $this->portalFixture(['portal.manage']);
        $inactiveInvitation = $this->storedInvitation($inactive);
        $inactive['portal']->update(['is_active' => false]);
        $this->postJson(
            '/api/v1/portal/invitations/'.$inactiveInvitation['token'].'/accept',
            $password,
        )->assertConflict();

        $this->assertDatabaseCount('portal_users', 0);
        foreach ([$changedInvitation, $deletedInvitation, $inactiveInvitation] as $stored) {
            $this->assertDatabaseHas('portal_invitations', [
                'id' => $stored['invitation']->id,
                'status' => 'PENDING',
            ]);
        }
    }

    public function test_only_portal_user_contact_and_email_unique_races_map_to_conflict(): void
    {
        $password = [
            'password' => 'Portal-Password!2026',
            'password_confirmation' => 'Portal-Password!2026',
        ];
        $contactRace = $this->portalFixture(['portal.manage']);
        $contactInvitation = $this->storedInvitation($contactRace);
        $this->createPortalUser($contactRace);
        $this->postJson(
            '/api/v1/portal/invitations/'.$contactInvitation['token'].'/accept',
            $password,
        )->assertConflict();

        $this->app['auth']->forgetGuards();
        $emailRace = $this->portalFixture(['portal.manage']);
        $emailInvitation = $this->storedInvitation($emailRace);
        $otherContact = Contact::factory()->create([
            'tenant_id' => $emailRace['tenant']->id,
            'email' => 'other-race@example.test',
        ]);
        PortalUser::create([
            'contact_id' => $otherContact->id,
            'email' => mb_strtolower((string) $emailRace['contact']->email),
            'password' => 'Portal-Password!2026',
            'status' => 'ACTIVE',
        ]);
        $this->postJson(
            '/api/v1/portal/invitations/'.$emailInvitation['token'].'/accept',
            $password,
        )->assertConflict();

        $this->assertDatabaseHas('portal_invitations', [
            'id' => $contactInvitation['invitation']->id,
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('portal_invitations', [
            'id' => $emailInvitation['invitation']->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_unrelated_query_exception_during_acceptance_is_rethrown(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($fixture);
        PortalUser::creating(function (): void {
            throw (new UniqueConstraintViolationException(
                'sqlite',
                'insert into portal_users (tenant_id, status) values (?, ?)',
                [],
                new PDOException('UNIQUE constraint failed: portal_users.tenant_id, portal_users.status'),
            ))->setIndex('portal_users_unrelated_unique')
                ->setColumns(['tenant_id', 'status']);
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/portal/invitations/'.$stored['token'].'/accept', [
                'password' => 'Portal-Password!2026',
                'password_confirmation' => 'Portal-Password!2026',
            ]);
            $this->fail('An unrelated query exception was swallowed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('portal_users_unrelated_unique', $exception->index ?? '');
        }

        $this->assertDatabaseCount('portal_users', 0);
        $this->assertDatabaseHas('portal_invitations', [
            'id' => $stored['invitation']->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_same_column_unique_violation_from_another_table_is_rethrown(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($fixture);
        DB::statement(<<<'SQL'
            CREATE TABLE unrelated_identity_records (
                tenant_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                UNIQUE (tenant_id, contact_id)
            )
        SQL);
        DB::table('unrelated_identity_records')->insert([
            'tenant_id' => $fixture['tenant']->id,
            'contact_id' => $fixture['contact']->id,
        ]);
        PortalUser::creating(function () use ($fixture): void {
            DB::table('unrelated_identity_records')->insert([
                'tenant_id' => $fixture['tenant']->id,
                'contact_id' => $fixture['contact']->id,
            ]);
        });
        $this->withoutExceptionHandling();

        $caught = null;
        try {
            $this->postJson('/api/v1/portal/invitations/'.$stored['token'].'/accept', [
                'password' => 'Portal-Password!2026',
                'password_confirmation' => 'Portal-Password!2026',
            ]);
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(UniqueConstraintViolationException::class, $caught);
        $this->assertStringContainsString(
            'unrelated_identity_records',
            $caught->getSql(),
        );
        $this->assertSame(['tenant_id', 'contact_id'], $caught->columns);
        $this->assertDatabaseCount('portal_users', 0);
        $this->assertDatabaseHas('portal_invitations', [
            'id' => $stored['invitation']->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_acceptance_rolls_back_user_and_state_when_audit_fails(): void
    {
        $fixture = $this->portalFixture(['portal.manage']);
        $stored = $this->storedInvitation($fixture);
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'portal.invitation.accepted') {
                throw new RuntimeException('Forced acceptance audit failure.');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/portal/invitations/'.$stored['token'].'/accept', [
                'password' => 'Portal-Password!2026',
                'password_confirmation' => 'Portal-Password!2026',
            ]);
            $this->fail('The acceptance audit failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced acceptance audit failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('portal_users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('portal_invitations', [
            'id' => $stored['invitation']->id,
            'status' => 'PENDING',
            'accepted_at' => null,
        ]);
    }

    public function test_activation_url_is_omitted_from_non_local_non_testing_responses(): void
    {
        Notification::fake();
        $fixture = $this->portalFixture(['portal.manage']);
        $originalEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            $this->internalApi($fixture)
                ->postJson('/api/v1/customer-portal/invitations', [
                    'contact_id' => $fixture['contact']->id,
                ])->assertCreated()
                ->assertJsonPath('meta.notification_sent', true)
                ->assertJsonMissingPath('meta.activation_url');
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }

        Notification::assertSentOnDemand(PortalInvitationNotification::class);
    }

    public function test_internal_invitation_routes_require_internal_auth_tenant_and_permission(): void
    {
        $fixture = $this->portalFixture(['contacts.view']);
        $headers = ['X-Tenant-ID' => (string) $fixture['tenant']->id];

        $this->postJson('/api/v1/customer-portal/invitations', [
            'contact_id' => $fixture['contact']->id,
        ], $headers)->assertUnauthorized();
        $this->withToken($fixture['token'])
            ->postJson('/api/v1/customer-portal/invitations', [
                'contact_id' => $fixture['contact']->id,
            ])->assertForbidden();
        $this->internalApi($fixture)
            ->postJson('/api/v1/customer-portal/invitations', [
                'contact_id' => $fixture['contact']->id,
            ])->assertForbidden();
        $this->internalApi($fixture)
            ->getJson('/api/v1/customer-portal/invitations')
            ->assertForbidden();
        $this->assertDatabaseCount('portal_invitations', 0);
    }

    public function test_public_invitation_limiter_allows_twenty_per_token_and_ip(): void
    {
        $firstToken = str_repeat('r', 64);
        $secondToken = str_repeat('s', 64);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->getJson('/api/v1/portal/invitations/'.$firstToken)->assertNotFound();
        }

        $this->getJson('/api/v1/portal/invitations/'.$firstToken)->assertTooManyRequests();
        $this->getJson('/api/v1/portal/invitations/'.$secondToken)->assertNotFound();
    }

    /** @return array<string, array{string, mixed}> */
    public static function forbiddenInvitationRootKeys(): array
    {
        return [
            'tenant id' => ['tenant_id', 99],
            'email' => ['email', 'attacker@example.test'],
            'token' => ['token', str_repeat('x', 64)],
            'status' => ['status', 'ACCEPTED'],
            'expiry' => ['expires_at', '2030-01-01T00:00:00Z'],
            'unknown null' => ['unexpected', null],
            'unknown empty string' => ['unexpected', ''],
            'unknown empty array' => ['unexpected', []],
        ];
    }

    /** @return array<string, array{string, mixed}> */
    public static function forbiddenAcceptanceRootKeys(): array
    {
        return [
            'tenant id' => ['tenant_id', 99],
            'contact id' => ['contact_id', 99],
            'email' => ['email', 'attacker@example.test'],
            'status' => ['status', 'ACCEPTED'],
            'token' => ['token', str_repeat('x', 64)],
            'unknown null' => ['unexpected', null],
            'unknown empty string' => ['unexpected', ''],
            'unknown empty array' => ['unexpected', []],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unusableInvitationStatuses(): array
    {
        return [
            'accepted' => ['ACCEPTED'],
            'revoked' => ['REVOKED'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidInspectionContactStates(): array
    {
        return [
            'soft deleted contact' => ['deleted'],
            'changed normalized email' => ['changed_email'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unknownInvitationListValues(): array
    {
        return [
            'non-empty string' => ['unexpected=blocked'],
            'empty string' => ['unexpected='],
            'null-style key without a value' => ['unexpected'],
            'array' => ['unexpected[]=nested'],
        ];
    }

    /** @return array<string, array{bool}> */
    public static function secretDeviceNames(): array
    {
        return [
            'exact token' => [true],
            'token contained in a larger name' => [false],
        ];
    }

    /**
     * @param  array{tenant: mixed, user: mixed, contact: Contact}  $fixture
     * @param  array<string, mixed>  $attributes
     * @return array{invitation: PortalInvitation, token: string}
     */
    private function storedInvitation(
        array $fixture,
        ?Contact $contact = null,
        array $attributes = [],
        ?string $token = null,
    ): array {
        app(TenantContext::class)->set((int) $fixture['tenant']->id);
        $contact ??= $fixture['contact'];
        $token ??= Str::random(64);
        $timestamps = Arr::only($attributes, ['created_at', 'updated_at']);
        $invitation = PortalInvitation::create(array_merge([
            'contact_id' => $contact->id,
            'invited_by' => $fixture['user']->id,
            'email' => mb_strtolower(trim((string) $contact->email)),
            'token_hash' => hash('sha256', $token),
            'status' => 'PENDING',
            'expires_at' => now()->addDays(7),
        ], Arr::except($attributes, ['created_at', 'updated_at'])));
        if ($timestamps !== []) {
            $invitation->forceFill($timestamps)->saveQuietly();
        }
        DB::table('portal_invitation_locators')->insert([
            'token_hash' => hash('sha256', $token),
            'invitation_id' => $invitation->id,
            'tenant_id' => $fixture['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['invitation' => $invitation->fresh(), 'token' => $token];
    }

    /** @param array<string, mixed> $payload */
    private function assertExternalPayloadHasNoInternalIds(array $payload): void
    {
        $forbidden = ['id', 'tenant_id', 'contact_id', 'invited_by', 'invitation_id', 'portal_id'];
        $walk = function (array $values) use (&$walk, $forbidden): void {
            foreach ($values as $key => $value) {
                $this->assertNotContains((string) $key, $forbidden);
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($payload);
    }

    private function assertDatabaseDoesNotContainPlaintext(string $plaintext): void
    {
        $tables = collect(DB::select(
            "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'",
        ))->pluck('name');

        foreach ($tables as $table) {
            $serialized = json_encode(DB::table((string) $table)->get(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(
                $plaintext,
                $serialized,
                'Plaintext secret was persisted in '.$table.'.',
            );
        }
    }
}
