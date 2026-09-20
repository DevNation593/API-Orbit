<?php

namespace Tests\Feature\Api;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\KnowledgeTestCase;

class KnowledgePostmanWorkflowTest extends KnowledgeTestCase
{
    use RefreshDatabase;

    public function test_collection_prerequest_keeps_public_knowledge_requests_free_of_tenant_headers(): void
    {
        $collection = json_decode(file_get_contents(base_path('docs/postman/Vantex CRM API.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        $scripts = collect($collection['event'] ?? [])->where('listen', 'prerequest')
            ->flatMap(fn (array $event): array => $event['script']['exec'])->all();
        $folder = collect($collection['item'])->firstWhere('name', '13 - API-7.2 base de conocimiento');
        $this->assertNotEmpty($scripts);
        $this->assertNotNull($folder);

        foreach ($folder['item'] as $index => $item) {
            $public = in_array($index, [8, 9, 11, 14, 16], true);
            $runner = new Process(['node', base_path('tests/Support/postman-script-runner.cjs')]);
            $runner->setInput(json_encode([
                'scripts' => $scripts,
                'variables' => ['tenant_id' => '321'],
                'request' => [
                    'headers' => (object) [],
                    'auth' => ['type' => $public ? 'noauth' : 'bearer'],
                ],
                'response' => ['status' => 0, 'body' => null],
            ], JSON_THROW_ON_ERROR));
            $runner->run();

            $this->assertTrue($runner->isSuccessful(), $item['name'].': '.$runner->getErrorOutput());
            $result = json_decode($runner->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $headers = array_change_key_case($result['request']['headers'], CASE_LOWER);
            $this->assertSame('application/json', $headers['accept'] ?? null);
            if ($public) {
                $this->assertArrayNotHasKey('x-tenant-id', $headers, $item['name'].' leaked tenant context through prerequest.');
            } else {
                $this->assertSame('321', $headers['x-tenant-id'] ?? null, $item['name'].' lost tenant context.');
            }
        }
    }

    public function test_knowledge_postman_workflow_executes_the_real_api_without_outbound_traffic(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $client = $this->createTenantUser();
        app(TenantContext::class)->set($client['tenant']->id);
        $collection = json_decode(file_get_contents(base_path('docs/postman/Vantex CRM API.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        $folder = collect($collection['item'])->firstWhere('name', '13 - API-7.2 base de conocimiento');
        $this->assertNotNull($folder, 'The API-7.2 workflow must exist in the shipped collection.');
        $items = $folder['item'];
        $names = array_column($items, 'name');
        $this->assertSame([
            'KB - Configurar base pública', 'KB - Crear categoría', 'KB - Crear etiqueta', 'KB - Crear artículo DRAFT v1',
            'KB - Consultar versión 1', 'KB - Editar artículo v2', 'KB - Listar historial', 'KB - Publicar v2',
            'KB - Listar público', 'KB - Detalle público v2', 'KB - Editar internamente v3', 'KB - Detalle público conserva v2',
            'KB - Restaurar versión 1 como v4', 'KB - Publicar v4', 'KB - Detalle público v1/v4', 'KB - Archivar artículo',
            'KB - Público devuelve 404', 'KB - Restaurar artículo a DRAFT',
        ], $names);
        $variables = ['run_id' => 'knowledge-workflow', 'tenant_id' => (string) $client['tenant']->id, 'token' => $client['token']];
        $visited = [];
        foreach ($items as $index => $item) {
            $visited[] = $item['name'];
            $public = in_array($index, [8, 9, 11, 14, 16], true);
            if ($public) {
                $this->assertSame('noauth', $item['request']['auth']['type'] ?? null, $item['name'].' must opt out of collection auth.');
                $this->assertFalse(collect($item['request']['header'] ?? [])->contains(fn ($header) => strtolower($header['key']) === 'x-tenant-id'));
            }
            $request = $item['request'];
            $resolve = fn (string $value): string => preg_replace_callback('/{{([a-z0-9_]+)}}/', function (array $match) use (&$variables): string {
                if ($match[1] === 'base_url') {
                    return '';
                }
                $this->assertArrayHasKey($match[1], $variables, 'Unresolved Postman variable '.$match[1]);

                return (string) $variables[$match[1]];
            }, $value);
            $body = isset($request['body']['raw']) ? json_decode($resolve($request['body']['raw']), true, flags: JSON_THROW_ON_ERROR) : [];
            $headers = collect($request['header'] ?? [])->filter(fn ($header) => ! ($header['disabled'] ?? false))
                ->mapWithKeys(fn ($header) => [$header['key'] => $resolve($header['value'])])->all();
            if (! $public) {
                $headers += ['Authorization' => 'Bearer '.$client['token'], 'X-Tenant-ID' => (string) $client['tenant']->id];
            }
            $response = $public
                ? $this->withoutHeader('Authorization')->withoutHeader('X-Tenant-ID')->withHeaders($headers)->json($request['method'], $resolve($request['url']), $body)
                : $this->withHeaders($headers)->json($request['method'], $resolve($request['url']), $body);
            $scripts = collect($item['event'] ?? [])->where('listen', 'test')->flatMap(fn ($event) => $event['script']['exec'])->all();
            $this->assertNotEmpty($scripts, $item['name'].' must have executable contract assertions.');
            $runner = new Process(['node', base_path('tests/Support/postman-script-runner.cjs')]);
            $runner->setInput(json_encode(['scripts' => $scripts, 'variables' => $variables, 'response' => ['status' => $response->status(), 'body' => $response->json()]], JSON_THROW_ON_ERROR));
            $runner->run();
            $this->assertTrue($runner->isSuccessful(), $item['name'].': '.$runner->getErrorOutput().' '.$response->getContent());
            $result = json_decode($runner->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertGreaterThan(0, $result['tests']);
            $variables = $result['variables'];
        }
        $this->assertSame($names, $visited);
        $this->assertDatabaseCount('knowledge_bases', 1);
        $this->assertDatabaseCount('knowledge_categories', 1);
        $this->assertDatabaseCount('knowledge_tags', 1);
        $this->assertDatabaseCount('knowledge_articles', 1);
        $this->assertDatabaseCount('knowledge_article_versions', 4);
        $this->assertDatabaseHas('knowledge_articles', ['id' => $variables['knowledge_article_id'], 'status' => 'DRAFT', 'published_version_id' => null]);
        Http::assertNothingSent();
    }
}
