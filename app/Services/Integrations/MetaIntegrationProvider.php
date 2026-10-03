<?php

namespace App\Services\Integrations;

final class MetaIntegrationProvider extends AbstractMetaGraphIntegrationProvider
{
    public function key(): string
    {
        return 'meta';
    }

    protected function label(): string
    {
        return 'Meta';
    }

    protected function description(): string
    {
        return 'Valida páginas, cuentas publicitarias u otros objetos de Meta Graph API.';
    }

    protected function objectSetting(): string
    {
        return 'app_id';
    }

    protected function objectLabel(): string
    {
        return 'App ID';
    }

    protected function objectTarget(): string
    {
        return 'credentials';
    }

    protected function additionalFields(): array
    {
        return [[
            'key' => 'app_secret', 'target' => 'credentials', 'label' => 'App secret',
            'type' => 'password', 'required' => false,
        ]];
    }
}
