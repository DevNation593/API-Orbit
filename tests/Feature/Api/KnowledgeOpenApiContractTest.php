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
}
