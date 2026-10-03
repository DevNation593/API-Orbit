<?php

use App\Jobs\ProcessSequenceEnrollmentJob;
use App\Jobs\SyncMeetingBookingJob;
use App\Models\Lead;
use App\Models\MeetingBooking;
use App\Models\SequenceEnrollment;
use App\Models\Task;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Calendar\CalendarProviderManager;
use App\Services\SequenceRunner;
use App\Services\TimelineEventRecorder;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('runs sequence steps idempotently and supports lifecycle and reply stops', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $lead = $api->postJson('/api/v1/leads', [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com',
    ])->assertCreated()->json('data');
    $sequence = $api->postJson('/api/v1/sequences', [
        'name' => 'Seguimiento demo',
        'status' => 'ACTIVE',
        'stop_conditions' => ['EMAIL_REPLY', 'MEETING_BOOKED'],
        'steps' => [
            [
                'type' => 'TASK', 'delay_minutes' => 0,
                'config' => ['title' => 'Llamar a {{lead.first_name}}', 'description' => 'Lead {{lead.email}}'],
            ],
            ['type' => 'WAIT', 'delay_minutes' => 0, 'config' => ['wait_minutes' => 15]],
            ['type' => 'TASK', 'delay_minutes' => 0, 'config' => ['title' => 'Seguimiento final']],
        ],
    ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
    $enrollment = $api->postJson('/api/v1/sequences/'.$sequence['id'].'/enrollments', [
        'lead_id' => $lead['id'], 'sender_user_id' => $client['user']->id,
    ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
    Queue::assertPushed(ProcessSequenceEnrollmentJob::class);

    $context = app(TenantContext::class);
    $context->set($client['tenant']->id);
    $model = SequenceEnrollment::findOrFail($enrollment['id']);
    $model->update(['next_run_at' => now()->subSecond()]);
    $execution = app(SequenceRunner::class)->run($model);
    expect($execution?->status)->toBe('completed')
        ->and(Task::query()->sole()->title)->toBe('Llamar a Ada')
        ->and(SequenceEnrollment::findOrFail($model->id)->current_position)->toBe(1);
    $waitExecution = app(SequenceRunner::class)->run($model->fresh());
    expect($waitExecution?->step?->type)->toBe('wait')
        ->and(SequenceEnrollment::findOrFail($model->id)->next_run_at?->isFuture())->toBeTrue();
    expect(app(SequenceRunner::class)->run($model->fresh()))->toBeNull()
        ->and(Task::query()->count())->toBe(1);
    $context->clear();

    $api->patchJson('/api/v1/sequences/'.$sequence['id'], [
        'steps' => [['type' => 'WAIT', 'config' => ['wait_minutes' => 5]]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['steps']);
    $api->postJson('/api/v1/sequence-enrollments/'.$enrollment['id'].'/pause', ['reason' => 'manual'])
        ->assertOk()->assertJsonPath('data.status', 'paused');
    $api->postJson('/api/v1/sequence-enrollments/'.$enrollment['id'].'/resume')
        ->assertOk()->assertJsonPath('data.status', 'active');

    $context->set($client['tenant']->id);
    app(TimelineEventRecorder::class)->record(Lead::findOrFail($lead['id']), 'email.received');
    expect(SequenceEnrollment::findOrFail($enrollment['id'])->status)->toBe('stopped')
        ->and(SequenceEnrollment::findOrFail($enrollment['id'])->stop_reason)->toBe('email_reply');
    $context->clear();
});

it('publishes UUID meeting links and books, replays, reschedules and cancels safely', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $date = CarbonImmutable::now('America/Guayaquil')->addDays(4)->startOfDay();
    $start = $date->setTime(9, 0);
    $meetingType = $api->postJson('/api/v1/meeting-types', [
        'name' => 'Demo comercial',
        'host_user_id' => $client['user']->id,
        'duration_minutes' => 30,
        'timezone' => 'America/Guayaquil',
        'minimum_notice_minutes' => 0,
        'maximum_days_ahead' => 30,
        'assignment_strategy' => 'FIXED',
        'location_type' => 'custom',
        'location_details' => ['label' => 'Sala virtual'],
        'availability' => [[
            'day_of_week' => $date->dayOfWeek,
            'start_time' => '09:00', 'end_time' => '12:00',
            'timezone' => 'America/Guayaquil',
        ]],
    ])->assertCreated()->assertJsonMissingPath('data.slug')->json('data');

    $this->getJson('/api/v1/public/meetings/'.$meetingType['public_id'])
        ->assertOk()->assertJsonPath('data.public_id', $meetingType['public_id'])
        ->assertJsonMissingPath('data.host_user_id')->assertJsonMissingPath('data.slug');
    $availability = $this->getJson('/api/v1/public/meetings/'.$meetingType['public_id'].'/availability?'.http_build_query([
        'date_from' => $date->format('Y-m-d'),
        'date_to' => $date->format('Y-m-d'),
        'timezone' => 'America/Guayaquil',
    ]))->assertOk()->json('data.slots');
    expect(collect($availability)->pluck('starts_at'))->toContain($start->utc()->toIso8601String());
    expect($availability[0])->not->toHaveKey('host_user_ids');

    $payload = [
        'starts_at' => $start->toIso8601String(),
        'timezone' => 'America/Guayaquil',
        'invitee_name' => 'Grace Hopper',
        'invitee_email' => 'grace@example.com',
        'invitee_phone' => '+593999111222',
        'idempotency_key' => 'meeting-booking-0001',
    ];
    $first = $this->postJson('/api/v1/public/meetings/'.$meetingType['public_id'].'/book', $payload)
        ->assertCreated()->assertJsonPath('meta.replayed', false)->json('data');
    expect($first['manage_token'])->toBeString()->not->toBeEmpty();
    $this->postJson('/api/v1/public/meetings/'.$meetingType['public_id'].'/book', $payload)
        ->assertOk()->assertJsonPath('meta.replayed', true)
        ->assertJsonPath('data.public_id', $first['public_id']);
    $this->assertDatabaseCount('meeting_bookings', 1);
    $this->assertDatabaseCount('contacts', 1);
    $this->assertDatabaseCount('leads', 1);
    $this->assertDatabaseCount('meeting_participants', 2);

    $this->postJson('/api/v1/public/meeting-bookings/'.$first['public_id'].'/reschedule', [
        'manage_token' => $first['manage_token'],
        'starts_at' => $start->addMinutes(30)->toIso8601String(),
        'timezone' => 'America/Guayaquil',
    ])->assertCreated()->assertJsonPath('data.status', 'confirmed');
    $old = MeetingBooking::query()->where('public_id', $first['public_id'])->firstOrFail();
    $new = MeetingBooking::query()->where('rescheduled_from_id', $old->id)->firstOrFail();
    expect($old->status)->toBe('rescheduled')->and($new->status)->toBe('confirmed');

    $this->postJson('/api/v1/public/meeting-bookings/'.$new->public_id.'/cancel', [
        'manage_token' => 'invalid-token',
    ])->assertUnprocessable()->assertJsonValidationErrors(['manage_token']);
    $newToken = (string) $new->manage_token;
    $this->postJson('/api/v1/public/meeting-bookings/'.$new->public_id.'/cancel', [
        'manage_token' => $newToken, 'reason' => 'No podré asistir',
    ])->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('assigns simultaneous meeting slots round robin without exposing tenant hosts', function (): void {
    Queue::fake();
    $client = $this->createTenantUser();
    $membership = TenantUser::query()->where('tenant_id', $client['tenant']->id)
        ->where('user_id', $client['user']->id)->firstOrFail();
    $second = User::factory()->create();
    TenantUser::create([
        'tenant_id' => $client['tenant']->id, 'user_id' => $second->id,
        'role_id' => $membership->role_id, 'status' => 'active', 'joined_at' => now(),
    ]);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $date = CarbonImmutable::now('America/Guayaquil')->addDays(5)->startOfDay();
    $start = $date->setTime(10, 0);
    $type = $api->postJson('/api/v1/meeting-types', [
        'name' => 'Round robin', 'duration_minutes' => 30, 'timezone' => 'America/Guayaquil',
        'minimum_notice_minutes' => 0, 'maximum_days_ahead' => 30,
        'assignment_strategy' => 'ROUND_ROBIN', 'location_type' => 'phone',
        'availability' => [
            ['user_id' => $client['user']->id, 'day_of_week' => $date->dayOfWeek, 'start_time' => '10:00', 'end_time' => '11:00'],
            ['user_id' => $second->id, 'day_of_week' => $date->dayOfWeek, 'start_time' => '10:00', 'end_time' => '11:00'],
        ],
    ])->assertCreated()->json('data');
    foreach ([['One', 'one@example.com'], ['Two', 'two@example.com']] as $index => [$name, $email]) {
        $this->postJson('/api/v1/public/meetings/'.$type['public_id'].'/book', [
            'starts_at' => $start->toIso8601String(), 'timezone' => 'America/Guayaquil',
            'invitee_name' => $name, 'invitee_email' => $email,
            'idempotency_key' => 'round-robin-'.($index + 1),
        ])->assertCreated();
    }
    expect(MeetingBooking::query()->orderBy('id')->pluck('host_user_id')->all())
        ->toBe([$client['user']->id, $second->id]);
    $this->postJson('/api/v1/public/meetings/'.$type['public_id'].'/book', [
        'starts_at' => $start->toIso8601String(), 'timezone' => 'America/Guayaquil',
        'invitee_name' => 'Three', 'invitee_email' => 'three@example.com',
    ])->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
});

it('synchronizes Google Meet and Zoom bookings with their connected providers', function (): void {
    Queue::fake();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/calendar/v3/freeBusy')) {
            return Http::response(['calendars' => ['primary' => ['busy' => []]]]);
        }
        if (str_contains($request->url(), 'googleapis.com/calendar/v3/calendars/primary/events')) {
            return Http::response(['id' => 'google-event-1', 'hangoutLink' => 'https://meet.google.com/test-room']);
        }
        if (str_contains($request->url(), 'api.zoom.us/v2/users/me/meetings')) {
            return Http::response(['id' => 987654321, 'join_url' => 'https://zoom.us/j/987654321'], 201);
        }

        return Http::response([], 404);
    });
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $date = CarbonImmutable::now('UTC')->addDays(6)->startOfDay();

    foreach ([
        ['google', 'google_meet', 'Google scheduler', 'google-token'],
        ['zoom', 'zoom', 'Zoom scheduler', 'zoom-token'],
    ] as [$provider, $location, $name, $token]) {
        $integration = $api->postJson('/api/v1/integrations', [
            'provider' => $provider, 'name' => $name, 'credentials' => ['access_token' => $token],
        ])->assertCreated()->json('data');
        DB::table('integrations')->where('id', $integration['id'])->update(['status' => 'active']);
        $connection = $api->postJson('/api/v1/calendar-connections', [
            'user_id' => $client['user']->id, 'integration_id' => $integration['id'],
            'provider' => $provider, 'external_calendar_id' => 'primary', 'timezone' => 'UTC',
        ])->assertCreated()->json('data');
        $type = $api->postJson('/api/v1/meeting-types', [
            'name' => $name, 'host_user_id' => $client['user']->id,
            'duration_minutes' => 30, 'timezone' => 'UTC', 'minimum_notice_minutes' => 0,
            'maximum_days_ahead' => 30, 'location_type' => $location,
            'availability' => [[
                'day_of_week' => $date->dayOfWeek, 'start_time' => '14:00', 'end_time' => '16:00', 'timezone' => 'UTC',
            ]],
        ])->assertCreated()->json('data');
        $booking = $this->postJson('/api/v1/public/meetings/'.$type['public_id'].'/book', [
            'starts_at' => $date->setTime(14, $provider === 'google' ? 0 : 30)->toIso8601String(),
            'timezone' => 'UTC', 'invitee_name' => ucfirst($provider).' Guest',
            'invitee_email' => $provider.'@example.com', 'idempotency_key' => $provider.'-booking-1',
        ])->assertCreated()->json('data');

        $context = app(TenantContext::class);
        (new SyncMeetingBookingJob($client['tenant']->id, MeetingBooking::query()
            ->where('public_id', $booking['public_id'])->value('id')))
            ->handle(app(CalendarProviderManager::class), $context);
        $synced = MeetingBooking::query()->where('public_id', $booking['public_id'])->firstOrFail();
        expect($synced->sync_status)->toBe('synced')
            ->and($synced->calendar_connection_id)->toBe($connection['id'])
            ->and($synced->conference_url)->toContain($provider === 'google' ? 'meet.google.com' : 'zoom.us');
    }

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'conferenceDataVersion=1')
        && data_get($request->data(), 'conferenceData.createRequest.conferenceSolutionKey.type') === 'hangoutsMeet');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.zoom.us/v2/users/me/meetings')
        && $request['type'] === 2 && $request['timezone'] === 'UTC');
});

it('delivers SMS through a connected Twilio inbox channel', function (): void {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['sid' => 'AC'.str_repeat('1', 32)], 200);
        }

        return Http::response(['sid' => 'SM'.str_repeat('2', 32), 'status' => 'queued'], 201);
    });
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $sid = 'AC'.str_repeat('1', 32);
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'twilio', 'name' => 'Twilio SMS',
        'credentials' => ['account_sid' => $sid, 'auth_token' => 'twilio-secret'],
        'settings' => ['from_number' => '+15551234567'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/integrations/'.$integration['id'].'/connect')->assertOk();
    $inbox = $api->postJson('/api/v1/inboxes', [
        'name' => 'SMS', 'channels' => [[
            'channel' => 'sms', 'name' => 'Twilio', 'integration_id' => $integration['id'],
        ]],
    ])->assertCreated()->json('data');
    $contact = $api->postJson('/api/v1/contacts', [
        'first_name' => 'SMS', 'phone' => '+593999111222',
    ])->assertCreated()->json('data');
    $conversation = $api->postJson('/api/v1/conversations', [
        'inbox_id' => $inbox['id'], 'inbox_channel_id' => $inbox['channels'][0]['id'],
        'contact_id' => $contact['id'],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/conversations/'.$conversation['id'].'/messages', [
        'body' => 'Mensaje real por Twilio', 'client_message_id' => 'twilio-sms-1',
    ])->assertStatus(202)->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.external_message_id', 'SM'.str_repeat('2', 32));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/Messages.json')
        && $request['To'] === '+593999111222'
        && $request['From'] === '+15551234567'
        && $request['Body'] === 'Mensaje real por Twilio');
});
