<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Support\UrlSafety;
use Illuminate\Support\Facades\Http;

final class VantexErpIntegrationProvider extends AbstractIntegrationProvider
{
    public function key(): string
    {
        return 'vantex_erp';
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(), 'label' => 'Vantex ERP',
            'description' => 'Sincroniza catálogo y cotizaciones aceptadas mediante una API HTTP versionada.',
            'fields' => [
                ['key' => 'api_token', 'target' => 'credentials', 'label' => 'API token', 'type' => 'password', 'required' => true],
                ['key' => 'base_url', 'target' => 'settings', 'label' => 'Base URL', 'type' => 'url', 'required' => true],
                ['key' => 'health_path', 'target' => 'settings', 'label' => 'Health path', 'type' => 'text', 'required' => false],
                ['key' => 'products_path', 'target' => 'settings', 'label' => 'Products upsert path', 'type' => 'text', 'required' => false],
                ['key' => 'sales_orders_path', 'target' => 'settings', 'label' => 'Sales orders path', 'type' => 'text', 'required' => false],
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return [
            'credentials.api_token' => ['required', 'string', 'between:16,2000'],
            'settings.base_url' => ['required', 'url:http,https', 'max:2000'],
            'settings.health_path' => ['nullable', 'string', 'regex:/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/', 'max:500'],
            'settings.products_path' => ['nullable', 'string', 'regex:/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/', 'max:500'],
            'settings.sales_orders_path' => ['nullable', 'string', 'regex:/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/', 'max:500'],
        ];
    }

    protected function checkHealth(Integration $integration): array
    {
        $url = rtrim((string) data_get($integration->settings, 'base_url'), '/').(string) data_get($integration->settings, 'health_path', '/api/health');
        if (! UrlSafety::isPublic($url)) {
            return $this->healthResult(false, null, 'La URL de Vantex ERP no es pública o segura.');
        }
        $response = Http::acceptJson()->withToken((string) data_get($integration->credentials, 'api_token'))
            ->timeout(12)->connectTimeout(5)->withOptions(['allow_redirects' => false])->get($url);

        return $this->healthResult($response->successful(), $response->status(), $response->successful() ? 'Conexión con Vantex ERP verificada.' : 'Vantex ERP rechazó la conexión.');
    }
}
