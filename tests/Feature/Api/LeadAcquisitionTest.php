<?php

use App\Jobs\ProcessLeadRoutingJob;
use App\Jobs\RecalculateLeadScoreJob;
use App\Models\Lead;
use App\Models\LeadCaptureEvent;
use App\Models\LeadRoutingAction;
use App\Models\ScoringEvent;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('publishes a form without slugs and captures an idempotent attributed lead and contact', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $form = $api->postJson('/api/v1/forms', [
        'name' => 'Website demo',
        'title' => 'Request a demo',
        'status' => 'published',
        'success_message' => 'Gracias, pronto te contactaremos.',
        'redirect_url' => 'https://example.test/thanks',
        'settings' => [
            'create_lead' => true,
            'create_contact' => true,
            'duplicate_strategy' => 'update',
            'minimum_fill_seconds' => 0,
        ],
        'fields' => [
            ['field_key' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'mapping_target' => 'lead.first_name', 'required' => true],
            ['field_key' => 'email', 'label' => 'Correo', 'type' => 'email', 'mapping_target' => 'lead.email', 'required' => true],
            ['field_key' => 'utm', 'label' => 'Origen', 'type' => 'text', 'mapping_target' => 'attribution.utm_source'],
        ],
    ])->assertCreated()->assertJsonPath('data.status', 'published')
        ->assertJsonMissingPath('data.slug');

    $formId = $form->json('data.id');
    $publicId = $form->json('data.public_id');
    expect($publicId)->toBeString()->not->toBeEmpty();

    $definition = $this->getJson('/api/v1/public/forms/'.$publicId)
        ->assertOk()->assertJsonPath('data.public_id', $publicId)
        ->assertJsonCount(3, 'data.fields');
    $session = $definition->json('data.form_session');

    $submission = $this->withHeader('Idempotency-Key', 'form-submit-0001')->postJson('/api/v1/public/forms/'.$publicId.'/submit', [
        'form_session' => $session,
        'fields' => ['first_name' => 'Ada', 'email' => 'ADA@EXAMPLE.COM', 'utm' => 'newsletter'],
        'attribution' => ['utm_campaign' => 'launch', 'landing_page' => 'https://example.test/demo'],
    ])->assertCreated()->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.redirect_url', 'https://example.test/thanks');

    $submissionId = $submission->json('data.submission_id');
    $lead = Lead::query()->sole();
    expect($lead->email_normalized)->toBe('ada@example.com')
        ->and($lead->capture_origin)->toBe('form')
        ->and($lead->utm_source)->toBe('newsletter')
        ->and($lead->utm_campaign)->toBe('launch')
        ->and($lead->contact_id)->not->toBeNull()
        ->and($lead->first_touch['origin'])->toBe('form')
        ->and($lead->last_touch['utm_source'])->toBe('newsletter');
    expect(LeadCaptureEvent::query()->count())->toBe(1);
    expect((string) DB::table('form_submissions')->value('payload'))->not->toContain('ADA@EXAMPLE.COM');

    $this->withHeader('Idempotency-Key', 'form-submit-0001')->postJson('/api/v1/public/forms/'.$publicId.'/submit', [
        'fields' => ['first_name' => 'Changed', 'email' => 'changed@example.com'],
    ])->assertOk()->assertJsonPath('meta.replayed', true)
        ->assertJsonPath('data.submission_id', $submissionId);
    expect(Lead::query()->count())->toBe(1)
        ->and(LeadCaptureEvent::query()->count())->toBe(1);

    $api->getJson('/api/v1/forms/'.$formId.'/submissions')->assertOk()->assertJsonCount(1, 'data');
    Queue::assertPushed(ProcessLeadRoutingJob::class);
    Queue::assertPushed(RecalculateLeadScoreJob::class);
});

it('verifies configured captcha and silently consumes honeypot submissions', function (): void {
    Queue::fake();
    config()->set('services.turnstile.secret_key', 'turnstile-secret');
    config()->set('services.turnstile.site_key', 'turnstile-site');
    Http::fake([
        'challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'example.test']),
    ]);
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $form = $api->postJson('/api/v1/forms', [
        'name' => 'Protected form',
        'title' => 'Protected',
        'status' => 'published',
        'settings' => [
            'create_lead' => true,
            'captcha_required' => true,
            'minimum_fill_seconds' => 0,
        ],
        'fields' => [
            ['field_key' => 'email', 'label' => 'Email', 'type' => 'email', 'mapping_target' => 'lead.email', 'required' => true],
        ],
    ])->assertCreated();
    $publicId = $form->json('data.public_id');
    $definition = $this->getJson('/api/v1/public/forms/'.$publicId)->assertOk()
        ->assertJsonPath('data.captcha.provider', 'turnstile')
        ->assertJsonPath('data.captcha.site_key', 'turnstile-site');

    $this->postJson('/api/v1/public/forms/'.$publicId.'/submit', [
        'form_session' => $definition->json('data.form_session'),
        'captcha_token' => 'valid-widget-token',
        'fields' => ['email' => 'captcha@example.com'],
    ])->assertCreated();
    Http::assertSent(fn ($request) => $request['secret'] === 'turnstile-secret'
        && $request['response'] === 'valid-widget-token');

    $this->postJson('/api/v1/public/forms/'.$publicId.'/submit', [
        '_honeypot' => 'spam-link',
        'fields' => ['email' => 'bot@example.com'],
    ])->assertStatus(202)->assertJsonPath('data.accepted', true);
    expect(Lead::query()->count())->toBe(1);
    $api->getJson('/api/v1/forms/'.$form->json('data.id').'/submissions?status=rejected')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.spam_score', 100);
});

it('routes leads deterministically and keeps candidates tenant safe', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $membership = TenantUser::query()->where('tenant_id', $client['tenant']->id)->where('user_id', $client['user']->id)->firstOrFail();
    $second = User::factory()->create();
    TenantUser::create([
        'tenant_id' => $client['tenant']->id,
        'user_id' => $second->id,
        'role_id' => $membership->role_id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $foreign = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $rule = $api->postJson('/api/v1/lead-routing-rules', [
        'name' => 'Website round robin',
        'strategy' => 'ROUND_ROBIN',
        'priority' => 1,
        'conditions' => [['field' => 'source', 'operator' => 'eq', 'value' => 'website']],
        'actions' => [
            ['user_id' => $client['user']->id, 'weight' => 1],
            ['user_id' => $second->id, 'weight' => 1],
        ],
    ])->assertCreated()->assertJsonPath('data.strategy', 'round_robin');
    expect($rule->json('data.actions'))->toHaveCount(2);

    $first = $api->postJson('/api/v1/leads', ['first_name' => 'First', 'source' => 'website'])->assertCreated()->json('data.id');
    $secondLead = $api->postJson('/api/v1/leads', ['first_name' => 'Second', 'source' => 'website'])->assertCreated()->json('data.id');
    $api->postJson('/api/v1/leads/'.$first.'/route')->assertOk()->assertJsonPath('data.selected_user_id', $client['user']->id);
    $api->postJson('/api/v1/leads/'.$secondLead.'/route')->assertOk()->assertJsonPath('data.selected_user_id', $second->id);
    expect(Lead::findOrFail($first)->owner_id)->toBe($client['user']->id)
        ->and(Lead::findOrFail($secondLead)->owner_id)->toBe($second->id)
        ->and(LeadRoutingAction::query()->sum('assignments_count'))->toBe(2);

    $this->app['auth']->forgetGuards();
    $foreignLead = $this->withToken($foreign['token'])->withHeader('X-Tenant-ID', (string) $foreign['tenant']->id)
        ->postJson('/api/v1/leads', ['first_name' => 'Foreign'])->assertCreated()->json('data.id');
    $this->app['auth']->forgetGuards();
    $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->postJson('/api/v1/leads/'.$foreignLead.'/route')->assertNotFound();

    $restricted = $this->createTenantUser(['lead_routing.view']);
    $this->app['auth']->forgetGuards();
    $this->withToken($restricted['token'])->withHeader('X-Tenant-ID', (string) $restricted['tenant']->id)
        ->postJson('/api/v1/lead-routing-rules', [])->assertForbidden();
});

it('calculates explicit negative and idempotent behavioral lead scoring', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $model = $api->postJson('/api/v1/scoring-models', [
        'name' => 'Default score',
        'minimum_score' => 0,
        'maximum_score' => 100,
        'warm_threshold' => 20,
        'hot_threshold' => 40,
        'is_default' => true,
    ])->assertCreated()->json('data.id');

    $api->postJson('/api/v1/scoring-models/'.$model.'/rules', [
        'name' => 'Has email', 'type' => 'EXPLICIT', 'points' => 20,
        'conditions' => [['field' => 'email', 'operator' => 'not_null']],
    ])->assertCreated()->assertJsonPath('data.points', 20);
    $api->postJson('/api/v1/scoring-models/'.$model.'/rules', [
        'name' => 'Bad source', 'type' => 'NEGATIVE', 'points' => 10,
        'conditions' => [['field' => 'source', 'operator' => 'eq', 'value' => 'bad']],
    ])->assertCreated()->assertJsonPath('data.points', -10);
    $api->postJson('/api/v1/scoring-models/'.$model.'/rules', [
        'name' => 'Website visit', 'type' => 'BEHAVIORAL', 'event_type' => 'website.visit',
        'points' => 15, 'repeatable' => false,
        'conditions' => [['field' => 'event.page', 'operator' => 'contains', 'value' => 'pricing']],
    ])->assertCreated();

    $lead = $api->postJson('/api/v1/leads', [
        'first_name' => 'Scored', 'email' => 'score@example.com', 'source' => 'bad',
    ])->assertCreated()->json('data.id');
    $api->postJson('/api/v1/leads/'.$lead.'/scores/recalculate')
        ->assertOk()->assertJsonPath('data.0.score', 10)->assertJsonPath('data.0.classification', 'cold');

    $api->postJson('/api/v1/leads/'.$lead.'/score-events', [
        'event_type' => 'website.visit', 'event_key' => 'visit-1', 'metadata' => ['page' => '/pricing'],
    ])->assertOk()->assertJsonPath('data.0.score', 25)->assertJsonPath('data.0.classification', 'warm');
    $api->postJson('/api/v1/leads/'.$lead.'/score-events', [
        'event_type' => 'website.visit', 'event_key' => 'visit-2', 'metadata' => ['page' => '/pricing'],
    ])->assertOk()->assertJsonPath('data.0.score', 25);

    expect(ScoringEvent::query()->count())->toBe(1)
        ->and(Lead::findOrFail($lead)->score)->toBe(25)
        ->and(Lead::findOrFail($lead)->score_classification)->toBe('warm');
});

it('replays manual lead capture idempotently and enforces API-3 permissions', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id)
        ->withHeader('Idempotency-Key', 'manual-lead-0001');
    $first = $api->postJson('/api/v1/leads', ['first_name' => 'Idempotent', 'email' => 'idem@example.com'])
        ->assertCreated()->assertJsonPath('meta.replayed', false);
    $api->postJson('/api/v1/leads', ['first_name' => 'Changed', 'email' => 'changed@example.com'])
        ->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $first->json('data.id'));
    expect(Lead::query()->count())->toBe(1)->and(LeadCaptureEvent::query()->count())->toBe(1);

    $restricted = $this->createTenantUser(['leads.view']);
    $restrictedApi = $this->withToken($restricted['token'])->withHeader('X-Tenant-ID', (string) $restricted['tenant']->id);
    $restrictedApi->getJson('/api/v1/forms')->assertForbidden();
    $restrictedApi->getJson('/api/v1/scoring-models')->assertForbidden();
    $restrictedApi->getJson('/api/v1/lead-routing-rules')->assertForbidden();
});
