<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class KnowledgeOpenApiContractTest extends TestCase
{
    public function test_knowledge_operations_are_documented_without_leaking_internal_auth_to_public_routes(): void
    {
        $yaml = file_get_contents(base_path('docs/openapi.yaml'));
        $operations = [
            '/knowledge/settings' => ['get', 'put'],
            '/knowledge/categories' => ['get', 'post'],
            '/knowledge/categories/{category}' => ['get', 'patch', 'delete'],
            '/knowledge/tags' => ['get', 'post'],
            '/knowledge/tags/{tag}' => ['get', 'patch', 'delete'],
            '/knowledge/articles' => ['get', 'post'],
            '/knowledge/articles/{article}' => ['get', 'patch'],
            '/knowledge/articles/{article}/versions' => ['get'],
            '/knowledge/articles/{article}/versions/{version}' => ['get'],
            '/knowledge/articles/{article}/versions/{version}/restore' => ['post'],
            '/knowledge/articles/{article}/publish' => ['post'],
            '/knowledge/articles/{article}/archive' => ['post'],
            '/knowledge/articles/{article}/restore' => ['post'],
            '/public/knowledge/{basePublicId}' => ['get'],
            '/public/knowledge/{basePublicId}/categories' => ['get'],
            '/public/knowledge/{basePublicId}/articles' => ['get'],
            '/public/knowledge/{basePublicId}/articles/{articlePublicId}' => ['get'],
        ];

        $this->assertSame(17, count($operations));
        foreach ($operations as $path => $methods) {
            $block = $this->pathBlock($yaml, $path);
            $this->assertNotSame('', $block, "Missing OpenAPI path {$path}.");
            preg_match_all('/^    (get|post|put|patch|delete):/m', $block, $matches);
            $this->assertSame($methods, $matches[1], "Unexpected methods for {$path}.");

            if (str_starts_with($path, '/public/')) {
                $this->assertStringNotContainsString('bearerAuth', $block);
                $this->assertStringNotContainsString('X-Tenant-ID', $block);
                $this->assertStringContainsString('security: []', $block, "{$path} must explicitly opt out of inherited Bearer auth.");
            } else {
                $this->assertStringContainsString('bearerAuth', $block);
                $this->assertStringContainsString('TenantHeader', $block);
            }
        }

        preg_match_all('/^    (get|post|put|patch|delete):/m', $this->pathsBlock($yaml), $all);
        $this->assertSame(508, count($all[1]), 'API-7.2 must add exactly 26 operations to the 482-operation baseline.');
        $this->assertStringContainsString('KnowledgeArticleDetail', $yaml);
        $this->assertStringContainsString('KnowledgeArticleSummary', $yaml);
        $this->assertStringContainsString('PUBLISHED', $yaml);
        $this->assertStringContainsString('CUSTOMER', $yaml);
        $this->assertStringNotContainsString("\n        slug:", $yaml);
    }

    public function test_knowledge_write_schemas_allow_only_client_editorial_fields_and_resolve_local_references(): void
    {
        $yaml = file_get_contents(base_path('docs/openapi.yaml'));
        $schemas = $this->knowledgeSchemas($yaml);
        $update = $this->schemaBlock($schemas, 'KnowledgeArticleUpdate');

        $this->assertStringNotContainsString('allOf:', $update);
        $this->assertStringContainsString('required: [expected_version]', $update);
        foreach ([
            'expected_version', 'title', 'summary', 'body_html', 'visibility',
            'category_id', 'tag_ids', 'seo_title', 'seo_description', 'change_summary',
        ] as $field) {
            $this->assertStringContainsString($field.':', $update);
        }
        foreach (['slug', 'public_id', 'status', 'current_version_id', 'published_version_id', 'created_by', 'updated_by', 'author_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden.':', $this->schemaBlock($schemas, 'KnowledgeArticleCreate'));
            $this->assertStringNotContainsString($forbidden.':', $update);
        }
        preg_match_all("/\$ref: '#\/components\/schemas\/([A-Za-z0-9]+)'/", $schemas, $references);
        foreach (array_unique($references[1]) as $reference) {
            $this->assertNotSame('', $this->schemaBlock($schemas, $reference), "Unresolved Knowledge schema {$reference}.");
        }
    }

    private function pathsBlock(string $yaml): string
    {
        $start = strpos($yaml, "paths:\n");
        $end = strpos($yaml, "components:\n");

        return substr($yaml, $start, $end - $start);
    }

    private function pathBlock(string $yaml, string $path): string
    {
        $paths = $this->pathsBlock($yaml);
        $needle = "  {$path}:\n";
        $start = strpos($paths, $needle);
        if ($start === false) {
            return '';
        }
        $rest = substr($paths, $start + strlen($needle));
        preg_match('/^  \/[^\n]+:\n/m', $rest, $next, PREG_OFFSET_CAPTURE);

        return $next === [] ? $rest : substr($rest, 0, $next[0][1]);
    }

    private function knowledgeSchemas(string $yaml): string
    {
        $start = strpos($yaml, '    # BEGIN API-7.2 KNOWLEDGE SCHEMAS');
        $end = strpos($yaml, '    # END API-7.2 KNOWLEDGE SCHEMAS');

        return substr($yaml, $start, $end - $start);
    }

    private function schemaBlock(string $schemas, string $name): string
    {
        $needle = "    {$name}:\n";
        $start = strpos($schemas, $needle);
        if ($start === false) {
            return '';
        }
        $rest = substr($schemas, $start + strlen($needle));
        preg_match('/^    [A-Za-z][A-Za-z0-9]+:\n/m', $rest, $next, PREG_OFFSET_CAPTURE);

        return $next === [] ? $rest : substr($rest, 0, $next[0][1]);
    }
}
