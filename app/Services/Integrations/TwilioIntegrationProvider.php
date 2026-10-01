<?php

namespace App\Services\Integrations;

use App\Models\Integration;

final class TwilioIntegrationProvider extends AbstractIntegrationProvider
{
    public function key(): string
    {
        return 'twilio';
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => 'Twilio',
            'description' => 'Valida una cuenta de Twilio con Account SID y Auth Token.',
            'fields' => [
                [
                    'key' => 'account_sid', 'target' => 'credentials', 'label' => 'Account SID',
                    'type' => 'password', 'required' => true, 'placeholder' => 'AC…',
                ],
                [
                    'key' => 'auth_token', 'target' => 'credentials', 'label' => 'Auth Token',
                    'type' => 'password', 'required' => true,
                ],
                [
                    'key' => 'from_number', 'target' => 'settings', 'label' => 'Número remitente',
                    'type' => 'text', 'required' => true, 'placeholder' => '+15551234567',
                ],
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return [
            'credentials.account_sid' => ['required', 'string', 'regex:/^AC[a-fA-F0-9]{32}$/'],
            'credentials.auth_token' => ['required', 'string', 'max:255'],
            'settings.from_number' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
        ];
    }

    protected function checkHealth(Integration $integration): array
    {
        $accountSid = (string) data_get($integration->credentials, 'account_sid');
        $response = $this->request()
            ->withBasicAuth($accountSid, (string) data_get($integration->credentials, 'auth_token'))
            ->get('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($accountSid).'.json');

        return $this->healthResult(
            $response->successful(),
            $response->status(),
            $response->successful() ? 'Conexión con Twilio verificada.' : 'Twilio rechazó las credenciales.',
        );
    }
}
