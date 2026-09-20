<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('validates custom fields on the backend', function (): void {
    $client = $this->createTenantUser();
    $request = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $request->postJson('/api/v1/field-definitions', [
        'entity_type' => 'contacts',
        'name' => 'budget',
        'label' => 'Budget',
        'type' => 'currency',
        'required' => true,
        'validation_rules' => ['min' => 0],
    ])->assertCreated();

    $request->postJson('/api/v1/contacts', [
        'first_name' => 'Invalid',
        'custom_fields' => ['budget' => -10],
    ])->assertStatus(422)->assertJsonValidationErrors(['custom_fields.budget']);

    $request->postJson('/api/v1/contacts', [
        'first_name' => 'Valid',
        'custom_fields' => ['budget' => 1000],
    ])->assertCreated()->assertJsonPath('data.custom_fields.budget', 1000);
});

it('creates a tenant and administrator through registration', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'A-secure-password-123',
        'password_confirmation' => 'A-secure-password-123',
        'tenant_name' => 'Education CRM',
        'industry' => 'education',
    ]);

    $response->assertCreated()->assertJsonPath('data.tenant.name', 'Education CRM');
    $this->assertDatabaseHas('tenant_user', [
        'user_id' => $response->json('data.user.id'),
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('permissions', ['key' => 'contacts.create']);
});

it('selects an authorized tenant by id during login', function (): void {
    $client = $this->createTenantUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => $client['user']->email,
        'password' => 'password',
        'tenant_id' => $client['tenant']->id,
    ])->assertOk()->assertJsonPath('data.tenant.id', $client['tenant']->id);

    $otherTenant = Tenant::factory()->create();
    $this->postJson('/api/v1/auth/login', [
        'email' => $client['user']->email,
        'password' => 'password',
        'tenant_id' => $otherTenant->id,
    ])->assertForbidden();
});
