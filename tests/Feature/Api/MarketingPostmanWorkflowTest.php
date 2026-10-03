<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('executes the API6 Postman workflow with chained ids without outbound traffic', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', $client['tenant']->id);
    $collection = json_decode(file_get_contents(base_path('docs/postman/Vantex CRM API.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
    $folder = collect($collection['item'])->first(fn ($item) => str_starts_with($item['name'], '11 - API-6'));
    $variables = [
        'run_id' => Str::random(12), 'user_id' => (string) $client['user']->id,
        'api6_scheduled_at' => now()->addDay()->toISOString(),
    ];
    foreach ($folder['item'] as $item) {
        $resolve = function (string $value) use (&$variables): string {
            return preg_replace_callback('/{{([a-z0-9_]+)}}/', function ($match) use (&$variables): string {
                if ($match[1] === 'base_url') {
                    return '';
                }
                if (! array_key_exists($match[1], $variables)) {
                    throw new RuntimeException('Unresolved Postman variable: '.$match[1]);
                }

                return $variables[$match[1]];
            }, $value);
        };
        $request = $item['request'];
        $body = isset($request['body']['raw']) ? json_decode($resolve($request['body']['raw']), true, flags: JSON_THROW_ON_ERROR) : [];
        $url = $resolve($request['url']);
        $response = $api->json($request['method'], $url, $body);
        $script = implode("\n", collect($item['event'])->where('listen', 'test')->first()['script']['exec']);
        preg_match('/pm\\.response\\.to\\.have\\.status\\((\\d+)\\)/', $script, $expected);
        $response->assertStatus((int) $expected[1]);
        preg_match_all("/pm\\.collectionVariables\\.set\\('([^']+)', String\\(data\\.([A-Za-z0-9_.\\[\\]]+)\\)\\)/", $script, $setters, PREG_SET_ORDER);
        foreach ($setters as $setter) {
            $key = preg_replace('/\\[(\\d+)\\]/', '.$1', $setter[2]);
            $value = data_get($response->json('data'), $key);
            expect($value, 'Postman variable '.$setter[1].' from '.$item['name'])->not->toBeNull();
            $variables[$setter[1]] = (string) $value;
        }
    }
    $this->assertDatabaseHas('campaigns', ['id' => $variables['campaign_id'], 'status' => 'cancelled']);
    $this->assertDatabaseHas('journey_enrollments', ['id' => $variables['journey_enrollment_id'], 'status' => 'cancelled', 'journey_version_id' => $variables['journey_version_id']]);
    $this->assertDatabaseCount('consent_records', 2);
    $this->assertDatabaseCount('messages', 0);
    Http::assertNothingSent();
});
