<?php

namespace Tests\Feature\Api;

use App\Models\SupportAgent;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SupportPostmanWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_postman_script_runner_does_not_expose_the_node_host(): void
    {
        $runner = new Process(['node', base_path('tests/Support/postman-script-runner.cjs')]);
        $runner->setInput(json_encode([
            'scripts' => [
                "pm.test('Node host is unavailable', function () { pm.collectionVariables.set('escaped', pm.test.constructor('return process.platform')()); });",
            ],
            'variables' => (object) [],
            'response' => ['status' => 200, 'body' => []],
        ], JSON_THROW_ON_ERROR));
        $runner->run();

        $this->assertFalse($runner->isSuccessful(), $runner->getOutput());
        $this->assertStringContainsString('Code generation from strings disallowed', $runner->getErrorOutput());
        $this->assertStringNotContainsString('"escaped"', $runner->getOutput());
    }

    public function test_postman_script_runner_cannot_forge_its_serialized_result(): void
    {
        $runner = new Process(['node', base_path('tests/Support/postman-script-runner.cjs')]);
        $runner->setInput(json_encode([
            'scripts' => ["JSON.stringify = () => 'null'; Object.prototype.toJSON = () => null; Array.prototype[Symbol.iterator] = function* () { while (true) { yield 'poison'; } };"],
            'variables' => ['safe' => 'value'],
            'request' => ['headers' => (object) []],
            'response' => ['status' => 200, 'body' => []],
        ], JSON_THROW_ON_ERROR));
        $runner->run();

        $this->assertTrue($runner->isSuccessful(), $runner->getErrorOutput());
        $result = json_decode($runner->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['tests']);
        $this->assertSame('value', $result['variables']['safe']);
    }

    public function test_collection_auth_and_tenant_prerequest_are_executable(): void
    {
        $collection = $this->collection();
        $this->assertSame('bearer', $collection['auth']['type'] ?? null);
        $token = collect($collection['auth']['bearer'] ?? [])->firstWhere('key', 'token');
        $this->assertSame('{{token}}', $token['value'] ?? null);
        $scripts = collect($collection['event'] ?? [])->where('listen', 'prerequest')
            ->flatMap(fn ($event) => $event['script']['exec'])->all();
        $this->assertNotEmpty($scripts);

        $runner = new Process(['node', base_path('tests/Support/postman-script-runner.cjs')]);
        $runner->setInput(json_encode([
            'scripts' => $scripts,
            'variables' => ['tenant_id' => '321'],
            'request' => ['headers' => (object) []],
            'response' => ['status' => 0, 'body' => null],
        ], JSON_THROW_ON_ERROR));
        $runner->run();

        $this->assertTrue($runner->isSuccessful(), $runner->getErrorOutput());
        $result = json_decode($runner->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('application/json', $result['request']['headers']['Accept'] ?? null);
        $this->assertSame('321', $result['request']['headers']['X-Tenant-ID'] ?? null);
    }

    #[DataProvider('agentFixtures')]
    public function test_postman_workflow_executes_real_requests_and_scripts_without_outbound_traffic(bool $existing): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-14 14:00:00', 'UTC'));
        try {
            $client = $this->createTenantUser();
            app(TenantContext::class)->set($client['tenant']->id);
            if ($existing) {
                SupportAgent::create(['user_id' => $client['user']->id]);
            }
            $collection = $this->collection();
            $folder = collect($collection['item'])->first(fn ($item) => $item['name'] === '12 - API-7.1 tickets y SLA');
            $this->assertNotNull($folder, 'The API-7.1 workflow must exist in the shipped collection.');
            $this->assertNotEmpty($folder['item']);
            $items = $folder['item'];
            $names = array_column($items, 'name');
            $variables = [
                'run_id' => $existing ? 'agent-existing' : 'agent-new',
                'user_id' => (string) $client['user']->id,
                'tenant_id' => (string) $client['tenant']->id,
                'token' => $client['token'],
            ];
            $resolve = function (string $text) use (&$variables): string {
                return preg_replace_callback('/{{([a-z0-9_]+)}}/', function ($match) use (&$variables): string {
                    if ($match[1] === 'base_url') {
                        return '';
                    }
                    $this->assertArrayHasKey($match[1], $variables, 'Unresolved variable '.$match[1]);

                    return (string) $variables[$match[1]];
                }, $text);
            };
            $preRequestScripts = collect($collection['event'] ?? [])->where('listen', 'prerequest')
                ->flatMap(fn ($event) => $event['script']['exec'])->all();
            $preRequestRunner = new Process(['node', base_path('tests/Support/postman-script-runner.cjs')]);
            $preRequestRunner->setInput(json_encode([
                'scripts' => $preRequestScripts, 'variables' => $variables,
                'request' => ['headers' => (object) []], 'response' => ['status' => 0, 'body' => null],
            ], JSON_THROW_ON_ERROR));
            $preRequestRunner->run();
            $this->assertTrue($preRequestRunner->isSuccessful(), $preRequestRunner->getErrorOutput());
            $preRequestResult = json_decode($preRequestRunner->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $variables = $preRequestResult['variables'];
            $bearer = collect($collection['auth']['bearer'] ?? [])->firstWhere('key', 'token');
            $this->assertSame('bearer', $collection['auth']['type'] ?? null);
            $headers = $preRequestResult['request']['headers'];
            $headers['Authorization'] = 'Bearer '.$resolve($bearer['value'] ?? '');

            $visited = [];
            for ($index = 0, $steps = 0; $index < count($items); $index++, $steps++) {
                $this->assertLessThan(100, $steps, 'Postman flow must not loop.');
                $item = $items[$index];
                $visited[] = $item['name'];
                $request = $item['request'];
                $requestHeaders = $headers;
                foreach ($request['header'] ?? [] as $header) {
                    if (! ($header['disabled'] ?? false)) {
                        $requestHeaders[$header['key']] = $resolve($header['value']);
                    }
                }
                $body = isset($request['body']['raw']) ? json_decode($resolve($request['body']['raw']), true, flags: JSON_THROW_ON_ERROR) : [];
                $response = $this->withHeaders($requestHeaders)->json($request['method'], $resolve($request['url']), $body);
                $scripts = collect($item['event'] ?? [])->where('listen', 'test')->flatMap(fn ($event) => $event['script']['exec'])->all();
                $this->assertNotEmpty($scripts, 'Every smoke request needs executable assertions.');
                $runner = new Process(['node', base_path('tests/Support/postman-script-runner.cjs')]);
                $runner->setInput(json_encode([
                    'scripts' => $scripts, 'variables' => $variables,
                    'response' => ['status' => $response->status(), 'body' => $response->json()],
                ], JSON_THROW_ON_ERROR));
                $runner->run();
                $this->assertTrue($runner->isSuccessful(), $item['name'].': '.$runner->getErrorOutput().' '.$response->getContent());
                $result = json_decode($runner->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertGreaterThan(0, $result['tests']);
                $variables = $result['variables'];
                if ($result['nextRequest'] !== null) {
                    $next = array_search($result['nextRequest'], $names, true);
                    $this->assertNotFalse($next, 'Script points to an unknown request.');
                    $index = $next - 1;
                }
            }
            $expectedVisited = $existing
                ? array_values(array_filter($names, fn ($name) => $name !== 'API7 - Registrar agente'))
                : $names;
            $this->assertSame($expectedVisited, $visited);
            $this->assertDatabaseHas('tickets', ['id' => $variables['support_ticket_id'], 'status' => 'CLOSED', 'priority' => 'HIGH']);
            $this->assertDatabaseHas('sla_executions', ['ticket_id' => $variables['support_ticket_id'], 'status' => 'CLOSED']);
            $this->assertDatabaseCount('support_agents', 1);
            $this->assertDatabaseCount('ticket_comments', 2);
            $this->assertDatabaseCount('messages', 0);
            Http::assertNothingSent();
        } finally {
            $this->travelBack();
        }
    }

    public static function agentFixtures(): array
    {
        return ['register agent' => [false], 'reuse agent' => [true]];
    }

    private function collection(): array
    {
        return json_decode(
            file_get_contents(base_path('docs/postman/Vantex CRM API.postman_collection.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
