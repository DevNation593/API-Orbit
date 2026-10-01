<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\TenantUser;
use App\Services\NotificationDispatcher;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps database notifications tenant-aware and supports read state and preferences', function (): void {
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();
    $otherRole = Role::query()->withoutGlobalScopes()->where('tenant_id', $other['tenant']->id)->firstOrFail();
    TenantUser::create([
        'tenant_id' => $other['tenant']->id,
        'user_id' => $client['user']->id,
        'role_id' => $otherRole->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $context = app(TenantContext::class);
    $context->set($client['tenant']->id);
    app(NotificationDispatcher::class)->send(
        $client['user'], 'conversation.received', 'Nuevo mensaje', 'Tienes una conversación pendiente.',
        ['conversation_id' => 10], '/conversations/10', 'high',
    );
    $context->set($other['tenant']->id);
    app(NotificationDispatcher::class)->send(
        $client['user'], 'task.overdue', 'Tarea vencida', 'Una tarea de otro tenant está vencida.',
    );
    $context->clear();

    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $notification = $api->getJson('/api/v1/notifications?state=unread')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'conversation.received')
        ->assertJsonPath('data.0.priority', 'high')
        ->assertJsonPath('data.0.context.conversation_id', 10)
        ->json('data.0');
    $api->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
    $api->patchJson('/api/v1/notifications/'.$notification['id'].'/read')->assertOk()->assertJsonPath('data.read_at', fn ($value) => is_string($value));
    $api->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 0);
    $api->patchJson('/api/v1/notifications/'.$notification['id'].'/unread')->assertOk()->assertJsonPath('data.read_at', null);
    $api->patchJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1);

    $api->putJson('/api/v1/notification-preferences', ['preferences' => [[
        'event' => 'conversation.received', 'channel' => 'in_app', 'enabled' => false,
    ]]])->assertOk()->assertJsonPath('data.0.enabled', false);
    $context->set($client['tenant']->id);
    app(NotificationDispatcher::class)->send(
        $client['user'], 'conversation.received', 'Silenciada', 'No debe persistirse.',
    );
    $context->clear();
    $api->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 1);

    $this->app['auth']->forgetGuards();
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $other['tenant']->id)
        ->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'task.overdue');
});

it('manages a web chat inbox and conversation lifecycle with idempotent messages', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Inbox', 'last_name' => 'Customer', 'email' => 'inbox@example.com',
    ])->assertCreated()->json('data');
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'Soporte web',
        'channels' => [[
            'channel' => 'WEB_CHAT', 'name' => 'Chat principal', 'external_identifier' => 'main-widget',
        ]],
    ])->assertCreated()->assertJsonPath('data.channels.0.channel', 'web_chat')->json('data');
    $channel = $inbox['channels'][0];
    $conversation = $api->postJson('/api/v1/conversations', [
        'inbox_id' => $inbox['id'], 'inbox_channel_id' => $channel['id'],
        'contact_id' => $contact['id'], 'subject' => 'Necesito ayuda', 'priority' => 'HIGH',
    ])->assertCreated()->assertJsonPath('data.channel', 'web_chat')->assertJsonPath('data.priority', 'high')->json('data');

    $first = $api->withHeader('Idempotency-Key', 'message-web-0001')
        ->postJson('/api/v1/conversations/'.$conversation['id'].'/messages', [
            'body' => 'Hola, ¿cómo podemos ayudarte?', 'client_message_id' => 'frontend-message-1',
        ])->assertStatus(202)->assertJsonPath('data.status', 'sent')->json('data');
    $api->withHeader('Idempotency-Key', 'message-web-0001')
        ->postJson('/api/v1/conversations/'.$conversation['id'].'/messages', [
            'body' => 'Hola, ¿cómo podemos ayudarte?', 'client_message_id' => 'frontend-message-1',
        ])->assertStatus(202)->assertJsonPath('data.id', $first['id']);
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseHas('activities', [
        'tenant_id' => $client['tenant']->id, 'type' => 'web_chat.sent', 'activityable_id' => $contact['id'],
    ]);

    $context = app(TenantContext::class);
    $context->set($client['tenant']->id);
    $inbound = Message::create([
        'conversation_id' => $conversation['id'], 'inbox_channel_id' => $channel['id'],
        'sender_contact_id' => $contact['id'], 'direction' => 'inbound', 'sender_type' => 'contact',
        'type' => 'text', 'body' => 'No puedo ingresar.', 'status' => 'received', 'occurred_at' => now(),
    ]);
    Conversation::findOrFail($conversation['id'])->update([
        'last_message_at' => $inbound->occurred_at, 'last_inbound_at' => $inbound->occurred_at,
    ]);
    $context->clear();

    $api->getJson('/api/v1/conversations?channel=web_chat&status=open&contact='.$contact['id'].'&unread=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.unread_count', 1);
    $api->patchJson('/api/v1/conversations/'.$conversation['id'].'/read')
        ->assertOk()->assertJsonPath('meta.unread_count', 0);
    $api->getJson('/api/v1/conversations?unread=0')->assertOk()->assertJsonPath('meta.total', 1);

    $role = Role::query()->where('tenant_id', $client['tenant']->id)->firstOrFail();
    $api->patchJson('/api/v1/conversations/'.$conversation['id'].'/assign', [
        'assigned_user_id' => $client['user']->id, 'assigned_role_id' => $role->id, 'reason' => 'Owner online',
    ])->assertOk()->assertJsonPath('data.assigned_user_id', $client['user']->id);
    $api->patchJson('/api/v1/conversations/'.$conversation['id'].'/status', ['status' => 'RESOLVED'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->assertDatabaseHas('conversation_assignments', [
        'conversation_id' => $conversation['id'], 'assigned_user_id' => $client['user']->id,
    ]);

    $tag = $api->postJson('/api/v1/tags', ['name' => 'Inbox VIP'])->assertCreated()->json('data');
    $api->postJson('/api/v1/tags/'.$tag['id'].'/assignments', [
        'entity_type' => 'conversation', 'entity_id' => $conversation['id'],
    ])->assertCreated();
    $api->getJson('/api/v1/conversations/'.$conversation['id'])
        ->assertOk()->assertJsonPath('data.tags.0.name', 'Inbox VIP');
    $api->getJson('/api/v1/conversations/'.$conversation['id'].'/messages')
        ->assertOk()->assertJsonPath('meta.total', 2);
});

it('enforces tenant boundaries and permissions across inbox resources', function (): void {
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();
    $foreignContact = $this->withToken($other['token'])->withHeader('X-Tenant-ID', (string) $other['tenant']->id)
        ->postJson('/api/v1/contacts', ['first_name' => 'Foreign'])->assertCreated()->json('data');
    $this->app['auth']->forgetGuards();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'Tenant inbox', 'channels' => [['channel' => 'web_chat', 'name' => 'Widget']],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/conversations', [
        'inbox_id' => $inbox['id'], 'inbox_channel_id' => $inbox['channels'][0]['id'],
        'contact_id' => $foreignContact['id'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

    $restricted = $this->createTenantUser(['inboxes.view', 'conversations.view']);
    $this->app['auth']->forgetGuards();
    $restrictedApi = $this->withToken($restricted['token'])
        ->withHeader('X-Tenant-ID', (string) $restricted['tenant']->id);
    $restrictedApi->getJson('/api/v1/inboxes')->assertOk();
    $restrictedApi->postJson('/api/v1/inboxes', ['name' => 'Denied'])->assertForbidden();
    $restrictedApi->postJson('/api/v1/conversations', [
        'inbox_id' => 1, 'inbox_channel_id' => 1,
    ])->assertUnprocessable();
    $restrictedApi->getJson('/api/v1/notifications')->assertForbidden();
});

it('creates and filters reusable canned responses', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $response = $api->postJson('/api/v1/canned-responses', [
        'title' => 'Saludo', 'shortcut' => 'HELLO', 'body' => 'Hola, ¿cómo podemos ayudarte?', 'channel' => 'WEB_CHAT',
    ])->assertCreated()->assertJsonPath('data.shortcut', 'hello')->json('data');
    $api->getJson('/api/v1/canned-responses?channel=web_chat&search=hell')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $response['id']);
    $api->postJson('/api/v1/canned-responses', [
        'title' => 'Duplicado', 'shortcut' => 'hello', 'body' => 'Otro',
    ])->assertUnprocessable()->assertJsonValidationErrors(['shortcut']);
});
