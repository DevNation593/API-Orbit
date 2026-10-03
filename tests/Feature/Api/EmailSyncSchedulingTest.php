<?php

use App\Models\EmailAccount;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('synchronizes Gmail threads and attachments with an encrypted incremental cursor', function (): void {
    Storage::fake((string) config('filesystems.default'));
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    Http::fake(function (Request $request) use ($encode) {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($url === 'https://openidconnect.googleapis.com/v1/userinfo') {
            return Http::response(['sub' => 'google-user'], 200);
        }
        if ($path === '/gmail/v1/users/me/profile') {
            return Http::response(['emailAddress' => 'sales@example.com', 'historyId' => '101'], 200);
        }
        if ($path === '/gmail/v1/users/me/messages') {
            return Http::response(['messages' => [['id' => 'gmail-in-1', 'threadId' => 'gmail-thread-1']]], 200);
        }
        if ($path === '/gmail/v1/users/me/messages/gmail-in-1') {
            return Http::response([
                'id' => 'gmail-in-1',
                'threadId' => 'gmail-thread-1',
                'historyId' => '101',
                'labelIds' => ['INBOX', 'UNREAD'],
                'internalDate' => '1788310800000',
                'snippet' => 'Necesito una cotización',
                'payload' => [
                    'mimeType' => 'multipart/mixed',
                    'headers' => [
                        ['name' => 'From', 'value' => 'Cliente Gmail <gmail.customer@example.com>'],
                        ['name' => 'To', 'value' => 'sales@example.com'],
                        ['name' => 'Subject', 'value' => 'Cotización Gmail'],
                        ['name' => 'Message-ID', 'value' => '<gmail-in-1@example.com>'],
                    ],
                    'parts' => [
                        ['mimeType' => 'text/plain', 'filename' => '', 'body' => ['data' => $encode('Necesito una cotización')]],
                        ['mimeType' => 'text/html', 'filename' => '', 'body' => ['data' => $encode('<p>Necesito una cotización</p><script>evil()</script>')]],
                        [
                            'mimeType' => 'application/pdf', 'filename' => 'requirements.pdf',
                            'body' => ['attachmentId' => 'gmail-attachment-1', 'size' => 12],
                        ],
                    ],
                ],
            ], 200);
        }
        if ($path === '/gmail/v1/users/me/messages/gmail-in-1/attachments/gmail-attachment-1') {
            return Http::response(['data' => $encode('%PDF-test%'), 'size' => 10], 200);
        }
        if ($path === '/gmail/v1/users/me/history') {
            return Http::response(['history' => [], 'historyId' => '102'], 200);
        }

        return Http::response(['unexpected' => $url], 500);
    });

    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'google', 'name' => 'Gmail sync',
        'credentials' => ['access_token' => 'google-sync-token'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk();
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'Gmail sync inbox', 'default_assignee_id' => $client['user']->id,
        'channels' => [[
            'channel' => 'email', 'name' => 'Gmail sync', 'address' => 'sales@example.com',
            'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $account = $api->postJson('/api/v1/email/accounts', [
        'inbox_channel_id' => $inbox['channels'][0]['id'], 'integration_id' => $integration['id'],
        'provider' => 'google', 'email_address' => 'sales@example.com',
    ])->assertCreated()->json('data');
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Cliente', 'last_name' => 'Gmail', 'email' => 'gmail.customer@example.com',
    ])->assertCreated()->json('data');

    $api->postJson('/api/v1/email/accounts/'.$account['id'].'/sync', ['limit' => 25])
        ->assertStatus(202)->assertJsonPath('data.status', 'queued');
    $message = Message::query()->where('external_message_id', 'gmail-in-1')->firstOrFail();
    expect($message->direction)->toBe('inbound')
        ->and($message->conversation->external_identifier)->toBe('gmail-thread-1')
        ->and($message->conversation->contact_id)->toBe($contact['id'])
        ->and(data_get($message->content, 'html'))->not->toContain('script', 'evil');
    $attachment = $message->attachments()->with('file')->firstOrFail();
    expect($attachment->file)->not->toBeNull()
        ->and(Storage::disk($attachment->file->disk)->get($attachment->file->path))->toBe('%PDF-test%');
    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $client['tenant']->id, 'event' => 'conversation.received',
    ]);
    $this->assertDatabaseHas('activities', [
        'activityable_id' => $contact['id'], 'type' => 'email.received',
    ]);
    expect(EmailAccount::findOrFail($account['id'])->sync_cursor)->toContain('"history_id":"101"');

    $api->postJson('/api/v1/email/accounts/'.$account['id'].'/sync')->assertStatus(202);
    expect(Message::query()->where('external_message_id', 'gmail-in-1')->count())->toBe(1)
        ->and(EmailAccount::findOrFail($account['id'])->sync_cursor)->toContain('"history_id":"102"');
});

it('synchronizes Microsoft Graph delta messages and attachments', function (): void {
    Storage::fake((string) config('filesystems.default'));
    $deltaLink = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=opaque-1';
    Http::fake(function (Request $request) use ($deltaLink) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1.0/me') {
            return Http::response(['id' => 'microsoft-user'], 200);
        }
        if ($path === '/v1.0/me/mailFolders/inbox/messages/delta') {
            return Http::response([
                'value' => [[
                    'id' => 'graph-in-1', 'conversationId' => 'graph-thread-1',
                    'internetMessageId' => '<graph-in-1@contoso.com>', 'subject' => 'Consulta Graph',
                    'body' => ['contentType' => 'html', 'content' => '<p>Mensaje Graph</p><script>bad()</script>'],
                    'bodyPreview' => 'Mensaje Graph',
                    'from' => ['emailAddress' => ['address' => 'graph.customer@contoso.com', 'name' => 'Graph Customer']],
                    'toRecipients' => [['emailAddress' => ['address' => 'seller@contoso.com']]],
                    'receivedDateTime' => '2026-09-02T03:00:00Z', 'hasAttachments' => true,
                    'isRead' => false, 'importance' => 'high',
                ]],
                '@odata.deltaLink' => $deltaLink,
            ], 200);
        }
        if ($path === '/v1.0/me/messages/graph-in-1/attachments') {
            return Http::response(['value' => [[
                'id' => 'graph-att-1', 'name' => 'brief.txt', 'contentType' => 'text/plain',
                'size' => 5, 'contentBytes' => base64_encode('brief'), 'isInline' => false,
            ]]], 200);
        }

        return Http::response(['unexpected' => $request->url()], 500);
    });

    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'microsoft', 'name' => 'Graph sync',
        'credentials' => ['access_token' => 'graph-sync-token'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk();
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'Graph sync inbox', 'default_assignee_id' => $client['user']->id,
        'channels' => [[
            'channel' => 'email', 'name' => 'Graph sync', 'address' => 'seller@contoso.com',
            'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $account = $api->postJson('/api/v1/email/accounts', [
        'inbox_channel_id' => $inbox['channels'][0]['id'], 'integration_id' => $integration['id'],
        'provider' => 'microsoft', 'email_address' => 'seller@contoso.com',
    ])->assertCreated()->json('data');
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Graph', 'last_name' => 'Customer', 'email' => 'graph.customer@contoso.com',
    ])->assertCreated()->json('data');

    $api->postJson('/api/v1/email/accounts/'.$account['id'].'/sync')->assertStatus(202);
    $message = Message::query()->where('external_message_id', 'graph-in-1')->firstOrFail();
    expect($message->conversation->contact_id)->toBe($contact['id'])
        ->and(data_get($message->content, 'html'))->not->toContain('script', 'bad')
        ->and($message->attachments()->count())->toBe(1)
        ->and(EmailAccount::findOrFail($account['id'])->sync_cursor)->toBe($deltaLink);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/mailFolders/inbox/messages/delta')
        && $request->hasHeader('Authorization', 'Bearer graph-sync-token')
        && $request->hasHeader('Prefer', 'odata.maxpagesize=100')
    );
});

it('persists scheduled delivery and dispatches it only when due', function (): void {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://openidconnect.googleapis.com/v1/userinfo') {
            return Http::response(['sub' => 'google-user'], 200);
        }
        if ($request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send') {
            return Http::response(['id' => 'gmail-scheduled-1', 'threadId' => 'thread-scheduled-1'], 200);
        }

        return Http::response([], 500);
    });
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'google', 'name' => 'Gmail scheduled',
        'credentials' => ['access_token' => 'scheduled-token'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk();
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'Scheduled inbox',
        'channels' => [[
            'channel' => 'email', 'name' => 'Scheduled Gmail', 'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/email/accounts', [
        'inbox_channel_id' => $inbox['channels'][0]['id'], 'integration_id' => $integration['id'],
        'provider' => 'google', 'email_address' => 'scheduled@example.com',
    ])->assertCreated();
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Scheduled', 'email' => 'recipient@example.com',
    ])->assertCreated()->json('data');
    $conversation = $api->postJson('/api/v1/conversations', [
        'inbox_id' => $inbox['id'], 'inbox_channel_id' => $inbox['channels'][0]['id'],
        'contact_id' => $contact['id'],
    ])->assertCreated()->json('data');
    $scheduledAt = now()->addMinutes(10)->toISOString();
    $message = $api->postJson('/api/v1/conversations/'.$conversation['id'].'/messages', [
        'subject' => 'Correo programado', 'body' => 'Se debe enviar después.',
        'scheduled_at' => $scheduledAt, 'client_message_id' => 'scheduled-client-1',
    ])->assertStatus(202)->assertJsonPath('data.status', 'scheduled')->json('data');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/messages/send'));

    $this->travel(11)->minutes();
    $this->artisan('inbox:dispatch-scheduled')->expectsOutput('Dispatched 1 scheduled message(s).')->assertSuccessful();
    $stored = Message::findOrFail($message['id']);
    expect($stored->status)->toBe('sent')
        ->and($stored->external_message_id)->toBe('gmail-scheduled-1')
        ->and($stored->scheduled_at)->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/messages/send'));
});

it('synchronizes WhatsApp Business templates without exposing credentials', function (): void {
    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v23.0/phone-template-1') {
            return Http::response(['id' => 'phone-template-1'], 200);
        }
        if ($path === '/v23.0/waba-template-1/message_templates') {
            return Http::response(['data' => [[
                'id' => 'template-external-1', 'name' => 'order_update', 'language' => 'es',
                'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'Tu pedido {{1}} fue actualizado']],
            ]]], 200);
        }

        return Http::response(['unexpected' => $request->url()], 500);
    });
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'whatsapp', 'name' => 'WhatsApp templates',
        'credentials' => [
            'access_token' => 'whatsapp-template-token', 'phone_number_id' => 'phone-template-1',
            'app_secret' => 'whatsapp-template-app-secret',
        ],
        'settings' => ['api_version' => 'v23.0', 'business_account_id' => 'waba-template-1'],
    ])->assertCreated()->assertJsonMissing(['credentials', 'whatsapp-template-token'])->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk();
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'WhatsApp template inbox',
        'channels' => [[
            'channel' => 'whatsapp', 'name' => 'WhatsApp templates', 'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $account = $api->postJson('/api/v1/whatsapp/accounts', [
        'inbox_channel_id' => $inbox['channels'][0]['id'], 'integration_id' => $integration['id'],
        'business_account_id' => 'waba-template-1', 'phone_number_id' => 'phone-template-1',
        'verify_token' => 'template-verify-token-12345',
    ])->assertCreated()->json('data');

    $api->postJson('/api/v1/whatsapp/accounts/'.$account['id'].'/templates/sync')
        ->assertOk()->assertJsonPath('data.synced', 1)->assertJsonPath('data.pages', 1);
    $api->getJson('/api/v1/whatsapp/accounts/'.$account['id'].'/templates')
        ->assertOk()->assertJsonPath('data.0.name', 'order_update')
        ->assertJsonPath('data.0.status', 'approved')
        ->assertJsonPath('data.0.category', 'utility');
    $this->assertDatabaseHas('whatsapp_templates', [
        'tenant_id' => $client['tenant']->id, 'name' => 'order_update', 'language' => 'es', 'status' => 'approved',
    ]);
});
