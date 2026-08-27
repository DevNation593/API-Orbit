<?php

use App\Models\Contact;
use App\Models\Integration;
use App\Models\WebhookInboundEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('does not allow a user from another tenant to read, update or delete a contact', function (): void {
    $tenantA = $this->createTenantUser();
    $tenantB = $this->createTenantUser();
    $contact = Contact::create([
        'tenant_id' => $tenantA['tenant']->id,
        'first_name' => 'Tenant A',
        'email' => 'a@example.com',
    ]);

    $client = $this->withToken($tenantB['token'])->withHeader('X-Tenant-ID', (string) $tenantB['tenant']->id);

    $client->getJson('/api/v1/contacts/'.$contact->id)->assertNotFound();
    $client->patchJson('/api/v1/contacts/'.$contact->id, ['first_name' => 'tampered'])->assertNotFound();
    $client->deleteJson('/api/v1/contacts/'.$contact->id)->assertNotFound();

    expect(Contact::withoutGlobalScopes()->find($contact->id)->first_name)->toBe('Tenant A');
});

it('enforces permissions independently from authentication', function (): void {
    $client = $this->createTenantUser(['contacts.view']);
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $request->getJson('/api/v1/contacts')->assertOk();
    $request->postJson('/api/v1/contacts', ['first_name' => 'Blocked'])->assertForbidden();
});

it('rejects a tenant selector that is not a membership', function (): void {
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();

    $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', (string) $other['tenant']->id)
        ->getJson('/api/v1/contacts')
        ->assertForbidden();
});

it('returns the active role permissions in the authenticated session', function (): void {
    $client = $this->createTenantUser(['contacts.view']);

    $response = $this->withToken($client['token'])
        ->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    expect($response->json('data.user.permissions'))->toContain('contacts.view');
});

it('isolates integrations and never returns encrypted credentials', function (): void {
    $tenantA = $this->createTenantUser();
    $tenantB = $this->createTenantUser();
    $requestA = $this->withToken($tenantA['token'])->withHeader('X-Tenant-ID', (string) $tenantA['tenant']->id);

    $response = $requestA->postJson('/api/v1/integrations', [
        'provider' => 'google',
        'name' => 'Workspace',
        'credentials' => ['client_secret' => 'do-not-return'],
        'settings' => ['calendar' => true],
        'status' => 'active',
    ])->assertCreated();

    expect($response->json('data.credentials'))->toBeNull();
    $integration = Integration::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    expect($integration->credentials)->toBe(['client_secret' => 'do-not-return']);
    expect((string) $integration->getRawOriginal('credentials'))->not->toContain('do-not-return');

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/integrations/'.$integration->id, [
        'Authorization' => 'Bearer '.$tenantB['token'],
        'X-Tenant-ID' => (string) $tenantB['tenant']->id,
    ])
        ->assertNotFound();
});

it('accepts a signed incoming webhook idempotently', function (): void {
    $client = $this->createTenantUser();
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $secret = Str::random(32);
    $endpoint = $request->postJson('/api/v1/webhooks/endpoints', [
        'name' => 'Inbound CRM',
        'url' => 'http://8.8.8.8/hook',
        'secret' => $secret,
        'events' => ['external.created'],
    ])->assertCreated()->json('data.endpoint');
    $token = hash_hmac('sha256', (string) $endpoint['id'], $secret);
    $payload = ['external_id' => 'evt-1'];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = [
        'HTTP_CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $raw, $secret),
        'HTTP_IDEMPOTENCY_KEY' => 'incoming-event-1',
        'HTTP_X_WEBHOOK_EVENT' => 'external.created',
    ];

    $this->call('POST', '/api/v1/webhooks/incoming/'.$endpoint['id'].'/'.$token, [], [], [], $headers, $raw)
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
    expect(WebhookInboundEvent::query()->where('endpoint_id', $endpoint['id'])->first()?->status)->toBe('processed');
    $this->call('POST', '/api/v1/webhooks/incoming/'.$endpoint['id'].'/'.$token, [], [], [], $headers, $raw)
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});
