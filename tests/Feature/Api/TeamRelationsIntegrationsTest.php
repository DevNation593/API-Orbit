<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('invites and registers a new team member without exposing the token hash', function (): void {
    Notification::fake();
    $client = $this->createTenantUser();
    $role = Role::create([
        'tenant_id' => $client['tenant']->id,
        'name' => 'Sales representative',
        'is_system' => false,
    ]);
    $request = $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $response = $request->postJson('/api/v1/users/invitations', [
        'email' => 'new.member@example.com',
        'role_id' => $role->id,
    ])->assertCreated()
        ->assertJsonPath('data.email', 'new.member@example.com')
        ->assertJsonMissingPath('data.token_hash');

    Notification::assertSentOnDemand(TenantInvitationNotification::class);
    $token = Str::afterLast((string) $response->json('meta.acceptance_url'), '/');
    expect($token)->toHaveLength(64);

    $this->postJson('/api/v1/auth/invitations/'.$token.'/accept', [
        'name' => 'New Member',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertCreated()
        ->assertJsonPath('data.user.email', 'new.member@example.com')
        ->assertJsonPath('data.tenant.id', $client['tenant']->id);

    $userId = User::query()->where('email', 'new.member@example.com')->value('id');
    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $client['tenant']->id,
        'user_id' => $userId,
        'role_id' => $role->id,
        'status' => 'active',
    ]);
    $this->getJson('/api/v1/auth/invitations/'.$token)->assertNotFound();
});

it('changes a member role only to a role from the active tenant', function (): void {
    $client = $this->createTenantUser();
    $member = User::factory()->create();
    $role = Role::create([
        'tenant_id' => $client['tenant']->id,
        'name' => 'Manager',
        'is_system' => false,
    ]);
    TenantUser::create([
        'tenant_id' => $client['tenant']->id,
        'user_id' => $member->id,
        'role_id' => $role->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $other = $this->createTenantUser();
    $foreignRole = Role::create([
        'tenant_id' => $other['tenant']->id,
        'name' => 'Foreign role',
        'is_system' => false,
    ]);
    $request = $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $request->patchJson('/api/v1/users/'.$member->id.'/role', [
        'role_id' => $role->id,
    ])->assertOk()->assertJsonPath('data.role.id', $role->id);

    $request->patchJson('/api/v1/users/'.$member->id.'/role', [
        'role_id' => $foreignRole->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
});

it('lists selectable records and creates tenant-safe relations', function (): void {
    $client = $this->createTenantUser();
    $contact = Contact::create([
        'tenant_id' => $client['tenant']->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);
    $organization = Organization::create([
        'tenant_id' => $client['tenant']->id,
        'name' => 'Analytical Engines',
    ]);
    $other = $this->createTenantUser();
    $foreignContact = Contact::create([
        'tenant_id' => $other['tenant']->id,
        'first_name' => 'Foreign',
    ]);
    $request = $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $request->getJson('/api/v1/relations/options')
        ->assertOk()
        ->assertJsonFragment(['label' => 'Ada Lovelace'])
        ->assertJsonFragment(['label' => 'Analytical Engines']);

    $payload = [
        'relation_type' => 'works_at',
        'from_type' => 'contact',
        'from_id' => (string) $contact->id,
        'to_type' => 'organization',
        'to_id' => (string) $organization->id,
    ];
    $request->postJson('/api/v1/relations', $payload)
        ->assertCreated()
        ->assertJsonPath('data.from_type', 'contact')
        ->assertJsonPath('data.to_type', 'organization');
    $request->postJson('/api/v1/relations', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['relation']);

    $request->postJson('/api/v1/relations', [
        ...$payload,
        'to_type' => 'contact',
        'to_id' => (string) $contact->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['to_id']);

    $request->postJson('/api/v1/relations', [
        ...$payload,
        'to_type' => 'contact',
        'to_id' => (string) $foreignContact->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['relation']);
});

it('encrypts integration credentials and verifies the provider before activation', function (): void {
    Http::fake([
        'openidconnect.googleapis.com/*' => Http::response(['sub' => '123'], 200),
    ]);
    $client = $this->createTenantUser();
    $request = $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $request->getJson('/api/v1/integrations/providers')
        ->assertOk()
        ->assertJsonFragment(['key' => 'google', 'label' => 'Google']);

    $integration = $request->postJson('/api/v1/integrations', [
        'provider' => 'google',
        'name' => 'Primary Google account',
        'credentials' => ['access_token' => 'secret-google-token'],
        'settings' => [],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.credentials')
        ->json('data');

    $storedCredentials = (string) DB::table('integrations')
        ->where('id', $integration['id'])
        ->value('credentials');
    expect($storedCredentials)->not->toContain('secret-google-token');

    $request->postJson('/api/v1/integrations/'.$integration['id'].'/connect')
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
    $request->postJson('/api/v1/integrations/'.$integration['id'].'/health')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.status', 200);
    $request->postJson('/api/v1/integrations/'.$integration['id'].'/disconnect')
        ->assertOk()
        ->assertJsonPath('data.status', 'disabled');

    Http::assertSent(fn ($outgoing): bool => $outgoing->url() === 'https://openidconnect.googleapis.com/v1/userinfo'
        && $outgoing->hasHeader('Authorization', 'Bearer secret-google-token'));
});
