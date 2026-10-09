<?php

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('sends tracked Gmail messages through an encrypted tenant integration', function (): void {
    Http::fake([
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-user'], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
            'id' => 'gmail-message-1', 'threadId' => 'gmail-thread-1',
        ], 200),
    ]);
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'google', 'name' => 'Gmail ventas',
        'credentials' => ['access_token' => 'google-secret-token'],
    ])->assertCreated()->assertJsonMissing(['credentials'])->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk()->assertJsonPath('data.status', 'active');
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'Correo ventas',
        'channels' => [[
            'channel' => 'email', 'name' => 'Gmail', 'address' => 'sales@example.com',
            'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $account = $api->postJson('/api/v1/email/accounts', [
        'inbox_channel_id' => $inbox['channels'][0]['id'], 'integration_id' => $integration['id'],
        'provider' => 'google', 'email_address' => 'sales@example.com', 'display_name' => 'Vantex Sales',
        'signature_html' => '<strong>Equipo</strong><script>signatureEvil()</script>',
        'settings' => ['track_opens' => true, 'track_clicks' => true],
    ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
    expect($account['signature_html'])->not->toContain('script', 'signatureEvil');

    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Email', 'last_name' => 'Recipient', 'email' => 'recipient@example.com',
    ])->assertCreated()->json('data');
    $conversation = $api->postJson('/api/v1/conversations', [
        'inbox_id' => $inbox['id'], 'inbox_channel_id' => $inbox['channels'][0]['id'],
        'contact_id' => $contact['id'], 'subject' => 'Oferta',
    ])->assertCreated()->json('data');
    $message = $api->postJson('/api/v1/conversations/'.$conversation['id'].'/messages', [
        'subject' => 'Tu oferta', 'body' => 'Consulta la oferta.',
        'content' => ['html' => '<p><a href="https://example.com/welcome">Ver oferta</a></p><script>bodyEvil()</script>'],
        'client_message_id' => 'gmail-client-1',
    ])->assertStatus(202)->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.external_message_id', 'gmail-message-1')->json('data');

    $encodedMime = null;
    Http::assertSent(function (Request $request) use (&$encodedMime): bool {
        if ($request->url() !== 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send') {
            return false;
        }
        $encodedMime = $request['raw'];

        return $request->hasHeader('Authorization', 'Bearer google-secret-token');
    });
    expect($encodedMime)->toBeString();
    $mime = quoted_printable_decode(base64_decode(
        strtr((string) $encodedMime, '-_', '+/').str_repeat('=', (4 - strlen((string) $encodedMime) % 4) % 4),
    ));
    expect($mime)->toContain('recipient@example.com')->not->toContain('bodyEvil', 'signatureEvil');
    preg_match('/track\/email\/open\/([A-Za-z0-9]{64})\.gif/', (string) $mime, $openMatch);
    preg_match('/track\/email\/click\/([A-Za-z0-9]{64})/', (string) $mime, $clickMatch);
    expect($openMatch[1] ?? null)->toBeString()->and($clickMatch[1] ?? null)->toBeString();

    $this->get('/api/v1/track/email/open/'.$openMatch[1].'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get('/api/v1/track/email/click/'.$clickMatch[1])->assertRedirect('https://example.com/welcome');
    $this->assertDatabaseHas('email_tracking_events', ['message_id' => $message['id'], 'event' => 'email.opened']);
    $this->assertDatabaseHas('email_tracking_events', ['message_id' => $message['id'], 'event' => 'email.clicked']);
    $this->assertDatabaseHas('activities', ['activityable_id' => $contact['id'], 'type' => 'email.sent']);
    $this->assertDatabaseHas('activities', ['activityable_id' => $contact['id'], 'type' => 'email.opened']);
    $this->assertDatabaseHas('activities', ['activityable_id' => $contact['id'], 'type' => 'email.clicked']);

    $template = $api->postJson('/api/v1/email/templates', [
        'name' => 'Bienvenida', 'subject' => 'Hola {{contact.first_name}}',
        'body_html' => '<p>Hola</p><script>alert(1)</script>', 'variables' => ['contact.first_name'],
    ])->assertCreated()->json('data');
    expect($template['body_html'])->not->toContain('script');
    $api->patchJson('/api/v1/email/templates/'.$template['id'], ['active' => false])
        ->assertOk()->assertJsonPath('data.active', false);
});

it('uses Microsoft Graph v1 sendMail with its documented JSON contract', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me' => Http::response(['id' => 'ms-user'], 200),
        'https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202, ['request-id' => 'ms-request-1']),
    ]);
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'microsoft', 'name' => 'Outlook', 'credentials' => ['access_token' => 'ms-secret-token'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk();
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'Outlook inbox', 'channels' => [[
            'channel' => 'email', 'name' => 'Outlook', 'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/email/accounts', [
        'inbox_channel_id' => $inbox['channels'][0]['id'], 'integration_id' => $integration['id'],
        'provider' => 'microsoft', 'email_address' => 'sender@contoso.com',
    ])->assertCreated();
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'Graph', 'email' => 'receiver@contoso.com',
    ])->assertCreated()->json('data');
    $conversation = $api->postJson('/api/v1/conversations', [
        'inbox_id' => $inbox['id'], 'inbox_channel_id' => $inbox['channels'][0]['id'],
        'contact_id' => $contact['id'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/conversations/'.$conversation['id'].'/messages', [
        'subject' => 'Graph test', 'body' => 'Microsoft body',
    ])->assertStatus(202)->assertJsonPath('data.status', 'sent');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.microsoft.com/v1.0/me/sendMail'
        && $request->method() === 'POST'
        && $request['message']['subject'] === 'Graph test'
        && data_get($request->data(), 'message.toRecipients.0.emailAddress.address') === 'receiver@contoso.com'
        && $request->hasHeader('Authorization', 'Bearer ms-secret-token')
    );
});

it('sends and ingests signed WhatsApp messages idempotently', function (): void {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['id' => 'phone-123'], 200);
        }

        return Http::response([
            'contacts' => [['input' => '593999111222', 'wa_id' => '593999111222']],
            'messages' => [['id' => 'wamid.outbound-1']],
        ], 200);
    });
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $appSecret = 'meta-app-secret-value';
    $verifyToken = 'verify-token-value-12345';
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'whatsapp', 'name' => 'WhatsApp Cloud',
        'credentials' => [
            'access_token' => 'whatsapp-secret-token', 'phone_number_id' => 'phone-123', 'app_secret' => $appSecret,
        ],
        'settings' => ['api_version' => 'v23.0', 'business_account_id' => 'waba-1'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk()->assertJsonPath('data.status', 'active');
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'WhatsApp soporte', 'default_assignee_id' => $client['user']->id,
        'channels' => [[
            'channel' => 'whatsapp', 'name' => 'WhatsApp principal', 'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $account = $api->postJson('/api/v1/whatsapp/accounts', [
        'inbox_channel_id' => $inbox['channels'][0]['id'], 'integration_id' => $integration['id'],
        'business_account_id' => 'waba-1', 'phone_number_id' => 'phone-123',
        'display_phone_number' => '+593 99 000 0000', 'verify_token' => $verifyToken,
    ])->assertCreated()->assertJsonMissing(['verify_token', 'verify_token_hash'])->json('data');

    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'WhatsApp', 'last_name' => 'Customer', 'phone' => '+593 999 111 222',
    ])->assertCreated()->json('data');
    $conversation = $api->postJson('/api/v1/conversations', [
        'inbox_id' => $inbox['id'], 'inbox_channel_id' => $inbox['channels'][0]['id'],
        'contact_id' => $contact['id'],
    ])->assertCreated()->json('data');
    $outbound = $api->postJson('/api/v1/conversations/'.$conversation['id'].'/messages', [
        'type' => 'text', 'body' => 'Hola por WhatsApp',
    ])->assertStatus(202)->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.external_message_id', 'wamid.outbound-1')->json('data');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v23.0/phone-123/messages'
        && $request['messaging_product'] === 'whatsapp'
        && $request['to'] === '593999111222'
        && data_get($request->data(), 'text.body') === 'Hola por WhatsApp'
    );

    $query = http_build_query([
        'hub.mode' => 'subscribe', 'hub.verify_token' => $verifyToken, 'hub.challenge' => 'challenge-123',
    ]);
    $this->get('/api/v1/webhooks/whatsapp?'.$query)->assertOk()->assertSeeText('challenge-123');

    $incoming = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'waba-1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => 'phone-123', 'display_phone_number' => '593990000000'],
                    'contacts' => [['wa_id' => '593999111222', 'profile' => ['name' => 'WA Customer']]],
                    'messages' => [[
                        'from' => '593999111222', 'id' => 'wamid.inbound-1',
                        'timestamp' => (string) now()->timestamp, 'type' => 'text',
                        'text' => ['body' => 'Necesito ayuda por WhatsApp'],
                    ]],
                ],
            ]],
        ]],
    ];
    $raw = json_encode($incoming, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $raw, $appSecret);
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $raw)->assertOk()->assertJsonPath('duplicate', false);
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $raw)->assertOk()->assertJsonPath('duplicate', true);
    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation['id'], 'external_message_id' => 'wamid.inbound-1',
        'direction' => 'inbound', 'status' => 'received',
    ]);
    $this->assertDatabaseCount('conversations', 1);
    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $client['tenant']->id, 'event' => 'conversation.received',
    ]);
    $this->assertDatabaseHas('activities', [
        'activityable_id' => $contact['id'], 'type' => 'whatsapp.received',
    ]);

    $statusPayload = [
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'phone-123'],
            'statuses' => [[
                'id' => 'wamid.outbound-1', 'status' => 'read', 'timestamp' => (string) now()->timestamp,
            ]],
        ]]]]],
    ];
    $statusRaw = json_encode($statusPayload, JSON_THROW_ON_ERROR);
    $statusSignature = 'sha256='.hash_hmac('sha256', $statusRaw, $appSecret);
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $statusSignature,
    ], $statusRaw)->assertOk();
    expect(Message::findOrFail($outbound['id'])->status)->toBe('read');

    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
    ], $raw)->assertUnauthorized();
    expect($account['phone_number_id'])->toBe('phone-123');
});
