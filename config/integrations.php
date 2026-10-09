<?php

use App\Services\Integrations\CustomHttpIntegrationProvider;
use App\Services\Integrations\GoogleIntegrationProvider;
use App\Services\Integrations\MetaIntegrationProvider;
use App\Services\Integrations\MicrosoftIntegrationProvider;
use App\Services\Integrations\SmtpIntegrationProvider;
use App\Services\Integrations\TwilioIntegrationProvider;
use App\Services\Integrations\VantexErpIntegrationProvider;
use App\Services\Integrations\WhatsAppIntegrationProvider;
use App\Services\Integrations\ZoomIntegrationProvider;

return [
    /*
     * These definitions are intentionally vendor-neutral. A production adapter
     * can be registered in "adapters" and override any catalog entry without
     * changing the API contract.
     */
    'providers' => [
        'google' => [
            'label' => 'Google',
            'description' => 'Calendario, correo y servicios de Google.',
            'required_credentials' => ['client_id', 'client_secret', 'access_token'],
            'credential_fields' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false],
                ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true],
                ['key' => 'access_token', 'label' => 'Access token', 'secret' => true],
            ],
        ],
        'microsoft' => [
            'label' => 'Microsoft',
            'description' => 'Microsoft 365, Outlook y calendario.',
            'required_credentials' => ['client_id', 'client_secret', 'access_token'],
            'credential_fields' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false],
                ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true],
                ['key' => 'access_token', 'label' => 'Access token', 'secret' => true],
            ],
        ],
        'smtp' => [
            'label' => 'SMTP',
            'description' => 'Correo saliente mediante un servidor SMTP por tenant.',
            'required_credentials' => ['host', 'port'],
            'credential_fields' => [
                ['key' => 'host', 'label' => 'Host', 'secret' => false],
                ['key' => 'port', 'label' => 'Puerto', 'secret' => false],
                ['key' => 'username', 'label' => 'Usuario', 'secret' => false],
                ['key' => 'password', 'label' => 'Contraseña', 'secret' => true],
                ['key' => 'encryption', 'label' => 'Cifrado', 'secret' => false],
                ['key' => 'imap_host', 'label' => 'Host IMAP', 'secret' => false],
                ['key' => 'imap_port', 'label' => 'Puerto IMAP', 'secret' => false],
                ['key' => 'imap_username', 'label' => 'Usuario IMAP', 'secret' => false],
                ['key' => 'imap_password', 'label' => 'Contraseña IMAP', 'secret' => true],
                ['key' => 'imap_encryption', 'label' => 'Cifrado IMAP', 'secret' => false],
                ['key' => 'imap_folder', 'label' => 'Carpeta IMAP', 'secret' => false],
            ],
        ],
        'whatsapp' => [
            'label' => 'WhatsApp Business',
            'description' => 'Mensajería mediante WhatsApp Cloud API.',
            'required_credentials' => ['phone_number_id', 'access_token', 'app_secret'],
            'credential_fields' => [
                ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'secret' => false],
                ['key' => 'access_token', 'label' => 'Access token', 'secret' => true],
                ['key' => 'app_secret', 'label' => 'App secret', 'secret' => true],
            ],
        ],
        'meta' => [
            'label' => 'Meta',
            'description' => 'Leads y eventos de Meta.',
            'required_credentials' => ['app_id', 'app_secret', 'access_token'],
            'credential_fields' => [
                ['key' => 'app_id', 'label' => 'App ID', 'secret' => false],
                ['key' => 'app_secret', 'label' => 'App secret', 'secret' => true],
                ['key' => 'access_token', 'label' => 'Access token', 'secret' => true],
            ],
        ],
        'twilio' => [
            'label' => 'Twilio',
            'description' => 'SMS, voz y mensajería transaccional.',
            'required_credentials' => ['account_sid', 'auth_token'],
            'credential_fields' => [
                ['key' => 'account_sid', 'label' => 'Account SID', 'secret' => false],
                ['key' => 'auth_token', 'label' => 'Auth token', 'secret' => true],
            ],
            'setting_fields' => [
                ['key' => 'from_number', 'label' => 'Número remitente E.164', 'secret' => false],
            ],
        ],
        'zoom' => [
            'label' => 'Zoom',
            'description' => 'Videoconferencias mediante Zoom OAuth.',
            'required_credentials' => ['access_token'],
            'credential_fields' => [
                ['key' => 'access_token', 'label' => 'Access token', 'secret' => true],
            ],
            'setting_fields' => [
                ['key' => 'user_id', 'label' => 'Usuario de Zoom', 'secret' => false],
            ],
        ],
        'vantex_erp' => [
            'label' => 'Vantex ERP',
            'description' => 'Catálogo y órdenes de venta mediante API HTTP.',
            'required_credentials' => ['api_token'],
            'credential_fields' => [
                ['key' => 'api_token', 'label' => 'API token', 'secret' => true],
            ],
            'setting_fields' => [
                ['key' => 'base_url', 'label' => 'Base URL', 'secret' => false],
            ],
        ],
        'custom_webhook' => [
            'label' => 'Webhook personalizado',
            'description' => 'Conecta cualquier servicio compatible con webhooks.',
            'required_credentials' => [],
            'credential_fields' => [],
        ],
    ],
    'adapters' => [
        GoogleIntegrationProvider::class,
        MicrosoftIntegrationProvider::class,
        SmtpIntegrationProvider::class,
        WhatsAppIntegrationProvider::class,
        MetaIntegrationProvider::class,
        TwilioIntegrationProvider::class,
        ZoomIntegrationProvider::class,
        VantexErpIntegrationProvider::class,
        CustomHttpIntegrationProvider::class,
    ],
];
