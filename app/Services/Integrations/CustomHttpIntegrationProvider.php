<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Rules\SafeExternalUrl;
use App\Support\UrlSafety;
use RuntimeException;

final class CustomHttpIntegrationProvider extends AbstractIntegrationProvider
{
    public function key(): string
    {
        return 'custom_webhook';
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => 'Otro servicio HTTP',
            'description' => 'Comprueba un endpoint HTTP(S) público con un token Bearer opcional.',
            'fields' => [
                [
                    'key' => 'health_url', 'target' => 'settings', 'label' => 'URL de comprobación',
                    'type' => 'url', 'required' => true, 'placeholder' => 'https://api.ejemplo.com/health',
                ],
                [
                    'key' => 'access_token', 'target' => 'credentials', 'label' => 'Token Bearer',
                    'type' => 'password', 'required' => false,
                ],
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return [
            'settings.health_url' => ['required', 'url:http,https', 'max:2000', new SafeExternalUrl],
            'credentials.access_token' => ['nullable', 'string', 'max:10000'],
        ];
    }

    protected function checkHealth(Integration $integration): array
    {
        $url = (string) data_get($integration->settings, 'health_url');
        if (! UrlSafety::isPublic($url)) {
            throw new RuntimeException('The health endpoint is no longer public.');
        }

        $request = $this->request();
        $token = data_get($integration->credentials, 'access_token');
        if (is_string($token) && $token !== '') {
            $request->withToken($token);
        }
        $response = $request->get($url);

        return $this->healthResult(
            $response->successful(),
            $response->status(),
            $response->successful() ? 'Endpoint externo verificado.' : 'El endpoint devolvió un error.',
        );
    }
}
