<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // The suite runs with the null broadcaster, which authorizes nothing.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]);
    require base_path('routes/channels.php');
});

it('authorizes the notification channel of the signed-in user with a bearer token', function (): void {
    $client = $this->createTenantUser();

    $this->withToken($client['token'])->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-App.Models.User.'.$client['user']->id,
    ])->assertOk()->assertJsonPath('auth', fn ($value) => is_string($value) && str_starts_with($value, 'test-key:'));
});

it('refuses the notification channel of another user', function (): void {
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();

    $this->withToken($client['token'])->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-App.Models.User.'.$other['user']->id,
    ])->assertForbidden();
});

it('refuses to authorize channels without a token', function (): void {
    $client = $this->createTenantUser();

    $this->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-App.Models.User.'.$client['user']->id,
    ])->assertUnauthorized();
});
