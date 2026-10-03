<?php

use App\Jobs\DeliverConversationMessageJob;
use App\Jobs\ProcessMarketingJob;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\Inbox;
use App\Models\InboxChannel;
use App\Models\Integration;
use App\Models\JourneyEnrollment;
use App\Models\Lead;
use App\Models\Message;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function marketingTestChannel(int $tenantId): InboxChannel
{
    $integration = Integration::create(['tenant_id' => $tenantId, 'provider' => 'google', 'name' => 'Fake Gmail', 'status' => 'active', 'credentials' => ['access_token' => 'fake-test-token']]);
    $inbox = Inbox::create(['tenant_id' => $tenantId, 'name' => 'Marketing']);
    $channel = InboxChannel::create(['tenant_id' => $tenantId, 'inbox_id' => $inbox->id, 'integration_id' => $integration->id, 'channel' => 'email', 'name' => 'Email', 'status' => 'active']);
    EmailAccount::create(['tenant_id' => $tenantId, 'inbox_channel_id' => $channel->id, 'integration_id' => $integration->id, 'provider' => 'google', 'email_address' => 'marketing@example.com', 'status' => 'active', 'settings' => ['track_opens' => true, 'track_clicks' => true]]);

    return $channel;
}

function marketingTestGraph(): array
{
    return ['entry' => 'start', 'nodes' => [
        ['id' => 'start', 'type' => 'start', 'next' => 'condition'],
        ['id' => 'condition', 'type' => 'condition', 'config' => ['definition' => ['field' => 'status', 'operator' => 'equals', 'value' => 'active']], 'on_true' => 'task', 'on_false' => 'end'],
        ['id' => 'task', 'type' => 'task', 'config' => ['title' => 'Marketing follow-up'], 'next' => 'wait'],
        ['id' => 'wait', 'type' => 'wait', 'config' => ['wait_minutes' => 15], 'next' => 'goal'],
        ['id' => 'goal', 'type' => 'goal', 'config' => ['definition' => ['field' => 'status', 'operator' => 'equals', 'value' => 'active']], 'next' => 'end'],
        ['id' => 'end', 'type' => 'end'],
    ]];
}

it('sends deduplicated consented campaign recipients and records tracking and exact revenue idempotently', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'campaign-mail-1', 'threadId' => 'thread-1'])]);
    $client = $this->createTenantUser();
    $channel = marketingTestChannel($client['tenant']->id);
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'email' => 'recipient@example.com']);
    $duplicate = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'email' => 'recipient@example.com']);
    $unconsented = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'email' => 'unknown@example.com']);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $audience = $api->postJson('/api/v1/audiences', ['name' => 'Launch list', 'type' => 'static', 'entity_type' => 'contacts'])->assertCreated()->json('data');
    $api->postJson('/api/v1/audiences/'.$audience['id'].'/members', ['entity_ids' => [$contact->id, $duplicate->id, $unconsented->id]])->assertOk();
    $api->postJson('/api/v1/consents', ['entity_type' => 'contacts', 'entity_id' => $contact->id, 'channel' => 'email', 'status' => 'opt_in', 'source' => 'form', 'evidence' => 'Checkbox accepted', 'idempotency_key' => 'campaign-grant'])->assertCreated();
    $campaign = $api->postJson('/api/v1/campaigns', [
        'name' => 'Launch', 'audience_id' => $audience['id'], 'sender_user_id' => $client['user']->id,
        'currency' => 'usd', 'cost' => '50.000001',
        'email' => ['inbox_channel_id' => $channel->id, 'subject' => 'Hola {{contact.first_name}}', 'body' => 'Your invitation',
            'body_html' => '<p><a href="https://example.com/offer">Offer</a></p><script>evil()</script>'],
    ])->assertCreated()->assertJsonPath('data.currency', 'USD')->assertJsonMissingPath('data.slug')->json('data');
    expect($campaign['email_campaign']['body_html'])->not->toContain('evil', 'script');
    $id = $campaign['id'];
    $api->postJson('/api/v1/campaigns/'.$id.'/launch')->assertStatus(202)->assertJsonPath('data.status', 'sending');
    $api->postJson('/api/v1/campaigns/'.$id.'/launch')->assertStatus(202);
    $api->patchJson('/api/v1/campaigns/'.$id, ['name' => 'Mutation'])->assertConflict();
    $api->deleteJson('/api/v1/audiences/'.$audience['id'])->assertConflict();
    (new ProcessMarketingJob($client['tenant']->id, 'campaign', $id))->handle(app(TenantContext::class));
    $members = $api->getJson('/api/v1/campaigns/'.$id.'/members')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
    $member = collect($members)->firstWhere('destination', 'recipient@example.com');
    expect($member['status'])->toBe('queued')->and(collect($members)->firstWhere('destination', 'unknown@example.com')['status'])->toBe('skipped');
    Queue::assertPushed(DeliverConversationMessageJob::class);
    $job = new DeliverConversationMessageJob($client['tenant']->id, $member['message_id']);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    Http::assertSentCount(1);
    app(TenantContext::class)->set($client['tenant']->id);
    $message = Message::findOrFail($member['message_id']);
    expect($message->status)->toBe('sent')->and($message->body)->toContain('public/preferences/');
    preg_match('/track\/email\/open\/([A-Za-z0-9]{64})/', $message->content['html'], $open);
    preg_match('/track\/email\/click\/([A-Za-z0-9]{64})/', $message->content['html'], $click);
    expect($open[1] ?? null)->toBeString()->and($click[1] ?? null)->toBeString();
    app(TenantContext::class)->clear();
    foreach ([1, 2] as $attempt) {
        $this->get('/api/v1/track/email/open/'.$open[1].'.gif')->assertOk();
        $this->get('/api/v1/track/email/click/'.$click[1])->assertRedirect('https://example.com/offer');
    }
    $event = ['campaign_member_id' => $member['id'], 'event' => 'converted', 'revenue' => '150.000003', 'idempotency_key' => 'order-123'];
    $api->postJson('/api/v1/campaigns/'.$id.'/events', $event)->assertCreated();
    $api->postJson('/api/v1/campaigns/'.$id.'/events', $event)->assertCreated();
    $api->postJson('/api/v1/campaigns/'.$id.'/events', array_replace($event, ['revenue' => '999']))->assertConflict();
    $api->getJson('/api/v1/campaigns/'.$id.'/metrics')->assertOk()->assertJsonPath('data.sent', 1)
        ->assertJsonPath('data.delivered', 1)->assertJsonPath('data.opened', 1)->assertJsonPath('data.clicked', 1)
        ->assertJsonPath('data.converted', 1)->assertJsonPath('data.revenue', '150.000003')->assertJsonPath('data.cost', '50.000001')
        ->assertJsonPath('data.roi', '200.000000')->assertJsonPath('data.skipped', 1);
    (new ProcessMarketingJob($client['tenant']->id, 'campaign', $id))->handle(app(TenantContext::class));
    $api->getJson('/api/v1/campaigns/'.$id)->assertOk()->assertJsonPath('data.status', 'completed');
    $this->assertDatabaseCount('messages', 1);
});

it('respects future schedules pauses and revocation after a campaign message has been queued', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
    $client = $this->createTenantUser();
    $channel = marketingTestChannel($client['tenant']->id);
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id]);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $audience = $api->postJson('/api/v1/audiences', ['name' => 'Scheduled', 'type' => 'static', 'entity_type' => 'contacts'])->assertCreated()->json('data');
    $api->postJson('/api/v1/audiences/'.$audience['id'].'/members', ['entity_ids' => [$contact->id]])->assertOk();
    $grant = ['entity_type' => 'contacts', 'entity_id' => $contact->id, 'channel' => 'email', 'status' => 'opt_in', 'source' => 'form', 'evidence' => 'Accepted', 'idempotency_key' => 'grant'];
    $api->postJson('/api/v1/consents', $grant)->assertCreated();
    $campaign = $api->postJson('/api/v1/campaigns', ['name' => 'Later', 'audience_id' => $audience['id'], 'sender_user_id' => $client['user']->id,
        'currency' => 'USD', 'scheduled_at' => now()->addHour()->toISOString(),
        'email' => ['inbox_channel_id' => $channel->id, 'subject' => 'Later', 'body' => 'Later'],
    ])->assertCreated()->json('data');
    $id = $campaign['id'];
    $api->postJson('/api/v1/campaigns/'.$id.'/launch')->assertStatus(202)->assertJsonPath('data.status', 'scheduled');
    (new ProcessMarketingJob($client['tenant']->id, 'campaign', $id))->handle(app(TenantContext::class));
    $this->assertDatabaseCount('messages', 0);
    $this->travel(61)->minutes();
    (new ProcessMarketingJob($client['tenant']->id, 'campaign', $id))->handle(app(TenantContext::class));
    $member = $api->getJson('/api/v1/campaigns/'.$id.'/members')->assertOk()->json('data.0');
    $api->postJson('/api/v1/campaigns/'.$id.'/pause')->assertStatus(202)->assertJsonPath('data.status', 'paused');
    app()->call([new DeliverConversationMessageJob($client['tenant']->id, $member['message_id']), 'handle']);
    $this->assertDatabaseHas('messages', ['id' => $member['message_id'], 'status' => 'queued']);
    $api->postJson('/api/v1/consents', array_replace($grant, ['status' => 'opt_out', 'idempotency_key' => 'revoke']))->assertCreated();
    $api->postJson('/api/v1/consents', $grant)->assertCreated();
    $api->postJson('/api/v1/campaigns/'.$id.'/resume')->assertStatus(202);
    app()->call([new DeliverConversationMessageJob($client['tenant']->id, $member['message_id']), 'handle']);
    $this->assertDatabaseHas('messages', ['id' => $member['message_id'], 'status' => 'cancelled']);
    $api->getJson('/api/v1/campaigns/'.$id.'/metrics')->assertOk()->assertJsonPath('data.sent', 0)->assertJsonPath('data.skipped', 1)->assertJsonPath('data.roi', null);
    Http::assertNothingSent();
    $this->travelBack();
});

it('runs immutable journey versions with branching waits goals and idempotent enrollment', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'status' => 'active']);
    $inactive = Contact::factory()->create(['tenant_id' => $client['tenant']->id, 'status' => 'inactive']);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $graph = marketingTestGraph();
    $journey = $api->postJson('/api/v1/journeys', ['name' => 'Nurture', 'entity_type' => 'contacts', 'graph' => $graph])->assertCreated()->json('data');
    $path = '/api/v1/journeys/'.$journey['id'];
    $api->postJson($path.'/publish', ['version_id' => $journey['versions'][0]['id']])->assertOk();
    $payload = ['entity_id' => $contact->id, 'sender_user_id' => $client['user']->id, 'idempotency_key' => 'enroll-1'];
    $enrollment = $api->postJson($path.'/enrollments', $payload)->assertCreated()->json('data');
    $api->postJson($path.'/enrollments', $payload)->assertCreated()->assertJsonPath('data.id', $enrollment['id']);
    $api->postJson($path.'/enrollments', array_replace($payload, ['entity_id' => $inactive->id]))->assertConflict();
    $api->postJson($path.'/enrollments', array_replace($payload, ['idempotency_key' => 'duplicate-active']))->assertConflict();
    $graph['nodes'][2]['config']['title'] = 'Version two task';
    $v2 = $api->postJson($path.'/versions', ['graph' => $graph])->assertCreated()->assertJsonPath('data.version', 2)->json('data');
    $api->postJson($path.'/publish', ['version_id' => $v2['id']])->assertOk();
    $runner = new ProcessMarketingJob($client['tenant']->id, 'journey', $enrollment['id']);
    foreach (range(1, 4) as $step) {
        $runner->handle(app(TenantContext::class));
    }
    $this->assertDatabaseHas('tasks', ['title' => 'Marketing follow-up']);
    $this->assertDatabaseMissing('tasks', ['title' => 'Version two task']);
    $runner->handle(app(TenantContext::class));
    $api->getJson($path.'/enrollments/'.$enrollment['id'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.current_node', 'goal')->assertJsonCount(4, 'data.executions')->assertJsonPath('data.version.version', 1);
    $api->postJson($path.'/enrollments/'.$enrollment['id'].'/pause')->assertOk()->assertJsonPath('data.status', 'paused');
    $this->travel(16)->minutes();
    $runner->handle(app(TenantContext::class));
    $api->postJson($path.'/enrollments/'.$enrollment['id'].'/resume')->assertOk();
    $runner->handle(app(TenantContext::class));
    $runner->handle(app(TenantContext::class));
    $completed = $api->getJson($path.'/enrollments/'.$enrollment['id'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonCount(5, 'data.executions')->json('data');
    expect($completed['goal_reached_at'])->not->toBeNull();
    $this->assertDatabaseCount('tasks', 1);
    $other = $api->postJson($path.'/enrollments', ['entity_id' => $inactive->id, 'sender_user_id' => $client['user']->id, 'idempotency_key' => 'enroll-2'])->assertCreated()->assertJsonPath('data.journey_version_id', $v2['id'])->json('data');
    foreach (range(1, 3) as $step) {
        (new ProcessMarketingJob($client['tenant']->id, 'journey', $other['id']))->handle(app(TenantContext::class));
    }
    $api->getJson($path.'/enrollments/'.$other['id'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.goal_reached_at', null);
    $this->assertDatabaseCount('tasks', 1);
    $api->deleteJson($path)->assertConflict();
    $api->patchJson($path.'/status', ['status' => 'archived'])->assertOk();
    $api->postJson($path.'/publish', ['version_id' => $v2['id']])->assertConflict();
    $this->travelBack();
});

it('rejects cyclic unreachable and cross tenant journey nodes and enforces read and enroll permissions', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $other = $this->createTenantUser();
    $foreignChannel = marketingTestChannel($other['tenant']->id);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $cyclic = marketingTestGraph();
    $cyclic['nodes'][3]['next'] = 'condition';
    $unreachable = marketingTestGraph();
    $unreachable['nodes'][] = ['id' => 'unused', 'type' => 'end'];
    $foreign = ['entry' => 'start', 'nodes' => [
        ['id' => 'start', 'type' => 'start', 'next' => 'email'],
        ['id' => 'email', 'type' => 'email', 'next' => 'end', 'config' => ['inbox_channel_id' => $foreignChannel->id, 'subject' => 'Invalid', 'body' => 'Invalid']],
        ['id' => 'end', 'type' => 'end'],
    ]];
    foreach ([$cyclic, $unreachable, $foreign] as $graph) {
        $api->postJson('/api/v1/journeys', ['name' => 'Invalid graph', 'entity_type' => 'contacts', 'graph' => $graph])->assertUnprocessable();
    }
    $this->assertDatabaseCount('journeys', 0);
    $journey = $api->postJson('/api/v1/journeys', ['name' => 'Valid', 'entity_type' => 'contacts', 'graph' => marketingTestGraph()])->assertCreated()->json('data');
    $reader = $this->createTenantUser(['journeys.view', 'campaigns.view', 'consent.view']);
    Sanctum::actingAs($reader['user']);
    $this->withHeader('X-Tenant-ID', $reader['tenant']->id)->getJson('/api/v1/journeys/'.$journey['id'])->assertNotFound();
    $this->postJson('/api/v1/journeys/'.$journey['id'].'/enrollments', [])->assertForbidden();
    $this->postJson('/api/v1/campaigns', [])->assertForbidden();
    $this->postJson('/api/v1/consents', [])->assertForbidden();
});

it('dispatches only due active marketing work and restores the previous tenant context', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $contact = Contact::factory()->create(['tenant_id' => $client['tenant']->id]);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $journey = $api->postJson('/api/v1/journeys', ['name' => 'Due', 'entity_type' => 'contacts', 'graph' => marketingTestGraph()])->assertCreated()->json('data');
    $path = '/api/v1/journeys/'.$journey['id'];
    $api->postJson($path.'/publish', ['version_id' => $journey['versions'][0]['id']])->assertOk();
    $enrollment = $api->postJson($path.'/enrollments', ['entity_id' => $contact->id, 'sender_user_id' => $client['user']->id, 'idempotency_key' => 'due'])->assertCreated()->json('data');
    Queue::fake();
    app(TenantContext::class)->set($client['tenant']->id);
    $this->artisan('marketing:dispatch-due', ['--limit' => 1])->expectsOutputToContain('Queued 1')->assertSuccessful();
    expect(app(TenantContext::class)->id())->toBe($client['tenant']->id);
    Queue::assertPushed(ProcessMarketingJob::class, fn ($job) => $job->kind === 'journey' && $job->recordId === $enrollment['id']);
    JourneyEnrollment::findOrFail($enrollment['id'])->update(['next_run_at' => now()->addHour()]);
    Queue::fake();
    $this->artisan('marketing:dispatch-due')->expectsOutputToContain('Queued 0')->assertSuccessful();
    Queue::assertNothingPushed();
    app(TenantContext::class)->clear();
});

it('keeps retryable lead deliveries queued until success or exhausted attempts', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::sequence()->push([], 503)->push(['id' => 'lead-marketing-sent'], 200)]);
    $client = $this->createTenantUser();
    $channel = marketingTestChannel($client['tenant']->id);
    $lead = Lead::create(['tenant_id' => $client['tenant']->id, 'first_name' => 'Lead', 'email' => 'lead@example.com']);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $audience = $api->postJson('/api/v1/audiences', ['name' => 'Lead list', 'type' => 'static', 'entity_type' => 'leads'])->assertCreated()->json('data');
    $api->postJson('/api/v1/audiences/'.$audience['id'].'/members', ['entity_ids' => [$lead->id]])->assertOk();
    $api->postJson('/api/v1/consents', ['entity_type' => 'leads', 'entity_id' => $lead->id, 'channel' => 'email', 'status' => 'opt_in', 'source' => 'form', 'evidence' => 'Accepted', 'idempotency_key' => 'lead-grant'])->assertCreated();
    $campaign = $api->postJson('/api/v1/campaigns', ['name' => 'Lead send', 'audience_id' => $audience['id'], 'sender_user_id' => $client['user']->id, 'currency' => 'USD', 'cost' => '3',
        'email' => ['inbox_channel_id' => $channel->id, 'subject' => 'Hello lead', 'body' => 'Lead body'],
    ])->assertCreated()->json('data');
    $path = '/api/v1/campaigns/'.$campaign['id'];
    $api->postJson($path.'/launch')->assertStatus(202);
    $process = new ProcessMarketingJob($client['tenant']->id, 'campaign', $campaign['id']);
    $process->handle(app(TenantContext::class));
    $member = $api->getJson($path.'/members')->assertOk()->json('data.0');
    $job = new DeliverConversationMessageJob($client['tenant']->id, $member['message_id']);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $process->handle(app(TenantContext::class));
    $api->getJson($path)->assertOk()->assertJsonPath('data.status', 'sending');
    $api->getJson($path.'/members')->assertOk()->assertJsonPath('data.0.status', 'queued');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    Http::assertSentCount(2);
    $api->postJson($path.'/events', ['campaign_member_id' => $member['id'], 'event' => 'converted', 'revenue' => '3.000001', 'idempotency_key' => 'small-roi'])->assertCreated();
    $api->getJson($path.'/metrics')->assertOk()->assertJsonPath('data.roi', '0.000033')->assertJsonPath('data.sent', 1);
    $process->handle(app(TenantContext::class));
    $api->getJson($path)->assertOk()->assertJsonPath('data.status', 'completed');
    app(TenantContext::class)->set($client['tenant']->id);
    $message = Message::findOrFail($member['message_id']);
    // Simulate a later campaign member that exhausted its delivery retries.
    $message->update(['status' => 'failed']);
    CampaignMember::findOrFail($member['id'])->update(['status' => 'queued']);
    $job->failed(new RuntimeException('Exhausted'));
    expect(CampaignMember::findOrFail($member['id'])->status)->toBe('failed');
    app(TenantContext::class)->clear();
});

it('checks consent for journey email nodes and suppresses a cancelled enrollment before delivery', function (): void {
    Queue::fake();
    Http::fake();
    $client = $this->createTenantUser();
    $channel = marketingTestChannel($client['tenant']->id);
    $first = Contact::factory()->create(['tenant_id' => $client['tenant']->id]);
    $second = Contact::factory()->create(['tenant_id' => $client['tenant']->id]);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $graph = ['entry' => 'start', 'nodes' => [
        ['id' => 'start', 'type' => 'start', 'next' => 'email'],
        ['id' => 'email', 'type' => 'email', 'next' => 'wait', 'config' => ['inbox_channel_id' => $channel->id, 'subject' => 'Journey hello', 'body' => 'Hello']],
        ['id' => 'wait', 'type' => 'wait', 'next' => 'end', 'config' => ['wait_minutes' => 60]],
        ['id' => 'end', 'type' => 'end'],
    ]];
    $journey = $api->postJson('/api/v1/journeys', ['name' => 'Email journey', 'entity_type' => 'contacts', 'graph' => $graph])->assertCreated()->json('data');
    $path = '/api/v1/journeys/'.$journey['id'];
    $api->postJson($path.'/publish', ['version_id' => $journey['versions'][0]['id']])->assertOk();
    $enrollment = $api->postJson($path.'/enrollments', ['entity_id' => $first->id, 'sender_user_id' => $client['user']->id, 'idempotency_key' => 'no-optin'])->assertCreated()->json('data');
    foreach ([1, 2] as $step) {
        (new ProcessMarketingJob($client['tenant']->id, 'journey', $enrollment['id']))->handle(app(TenantContext::class));
    }
    $this->assertDatabaseCount('messages', 0);
    $api->getJson($path.'/enrollments/'.$enrollment['id'])->assertOk()->assertJsonPath('data.executions.1.output.reason', 'consent_required');
    $api->postJson('/api/v1/consents', ['entity_type' => 'contacts', 'entity_id' => $second->id, 'channel' => 'email', 'status' => 'opt_in', 'source' => 'form', 'evidence' => 'Accepted', 'idempotency_key' => 'journey-grant'])->assertCreated();
    $enrollment = $api->postJson($path.'/enrollments', ['entity_id' => $second->id, 'sender_user_id' => $client['user']->id, 'idempotency_key' => 'has-optin'])->assertCreated()->json('data');
    foreach ([1, 2] as $step) {
        (new ProcessMarketingJob($client['tenant']->id, 'journey', $enrollment['id']))->handle(app(TenantContext::class));
    }
    $detail = $api->getJson($path.'/enrollments/'.$enrollment['id'])->assertOk()->json('data');
    $messageId = $detail['executions'][1]['output']['message_id'];
    $api->postJson($path.'/enrollments/'.$enrollment['id'].'/cancel')->assertOk();
    app()->call([new DeliverConversationMessageJob($client['tenant']->id, $messageId), 'handle']);
    $this->assertDatabaseHas('messages', ['id' => $messageId, 'status' => 'cancelled']);
    Http::assertNothingSent();
});
