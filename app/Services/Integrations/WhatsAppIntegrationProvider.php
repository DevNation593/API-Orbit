<?php

namespace App\Services\Integrations;

final class WhatsAppIntegrationProvider extends AbstractMetaGraphIntegrationProvider
{
    public function key(): string
    {
        return 'whatsapp';
    }

    protected function label(): string
    {
        return 'WhatsApp Business';
    }

    protected function description(): string
    {
        return 'Valida un número de WhatsApp Business mediante Meta Graph API.';
    }

    protected function objectSetting(): string
    {
        return 'phone_number_id';
    }

    protected function objectLabel(): string
    {
        return 'Phone number ID';
    }

    protected function objectTarget(): string
    {
        return 'credentials';
    }

    protected function additionalFields(): array
    {
        return [
            [
                'key' => 'app_secret', 'target' => 'credentials', 'label' => 'App secret',
                'type' => 'password', 'required' => true,
            ],
            [
                'key' => 'business_account_id', 'target' => 'settings', 'label' => 'WhatsApp Business Account ID',
                'type' => 'text', 'required' => false,
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return array_merge(parent::configurationRules(), [
            'credentials.app_secret' => ['required', 'string', 'max:4000'],
            'settings.business_account_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,190}$/'],
        ]);
    }
}
