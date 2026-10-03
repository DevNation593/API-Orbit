<?php

namespace App\Services\Integrations;

use App\Models\Integration;

final class SmtpIntegrationProvider extends AbstractIntegrationProvider
{
    public function key(): string
    {
        return 'smtp';
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => 'SMTP',
            'description' => 'Servidor SMTP con recepción IMAP opcional por tenant.',
            'fields' => [
                ['key' => 'host', 'target' => 'credentials', 'label' => 'Host', 'type' => 'text', 'required' => true],
                ['key' => 'port', 'target' => 'credentials', 'label' => 'Puerto', 'type' => 'number', 'required' => true],
                ['key' => 'username', 'target' => 'credentials', 'label' => 'Usuario', 'type' => 'text', 'required' => false],
                ['key' => 'password', 'target' => 'credentials', 'label' => 'Contraseña', 'type' => 'password', 'required' => false],
                ['key' => 'encryption', 'target' => 'credentials', 'label' => 'Cifrado', 'type' => 'select', 'required' => false, 'options' => ['tls', 'ssl', 'none']],
                ['key' => 'imap_host', 'target' => 'credentials', 'label' => 'Host IMAP', 'type' => 'text', 'required' => false],
                ['key' => 'imap_port', 'target' => 'credentials', 'label' => 'Puerto IMAP', 'type' => 'number', 'required' => false],
                ['key' => 'imap_username', 'target' => 'credentials', 'label' => 'Usuario IMAP', 'type' => 'text', 'required' => false],
                ['key' => 'imap_password', 'target' => 'credentials', 'label' => 'Contraseña IMAP', 'type' => 'password', 'required' => false],
                ['key' => 'imap_encryption', 'target' => 'credentials', 'label' => 'Cifrado IMAP', 'type' => 'select', 'required' => false, 'options' => ['tls', 'ssl', 'none']],
                ['key' => 'imap_folder', 'target' => 'credentials', 'label' => 'Carpeta IMAP', 'type' => 'text', 'required' => false],
            ],
        ];
    }

    protected function configurationRules(): array
    {
        return [
            'credentials.host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'credentials.port' => ['required', 'integer', 'between:1,65535'],
            'credentials.username' => ['nullable', 'string', 'max:1000'],
            'credentials.password' => ['nullable', 'string', 'max:4000'],
            'credentials.encryption' => ['nullable', 'in:tls,ssl,none'],
            'credentials.imap_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'credentials.imap_port' => ['nullable', 'integer', 'between:1,65535', 'required_with:credentials.imap_host'],
            'credentials.imap_username' => ['nullable', 'string', 'max:1000', 'required_with:credentials.imap_host'],
            'credentials.imap_password' => ['nullable', 'string', 'max:4000'],
            'credentials.imap_encryption' => ['nullable', 'in:tls,ssl,none'],
            'credentials.imap_folder' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        ];
    }

    protected function checkHealth(Integration $integration): array
    {
        $host = (string) data_get($integration->credentials, 'host');
        $port = (int) data_get($integration->credentials, 'port');
        $errorCode = 0;
        $errorMessage = '';
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 5);
        $connected = is_resource($socket);
        if ($connected) {
            fclose($socket);
        }

        $imapHost = (string) data_get($integration->credentials, 'imap_host');
        if ($connected && $imapHost !== '') {
            $imapPort = (int) data_get($integration->credentials, 'imap_port', 993);
            $imapSocket = @fsockopen($imapHost, $imapPort, $errorCode, $errorMessage, 5);
            $connected = is_resource($imapSocket);
            if ($connected) {
                fclose($imapSocket);
            }
        }

        return $this->healthResult(
            $connected,
            $connected ? 200 : null,
            $connected ? 'Los servidores de correo configurados son accesibles.' : 'No se pudo conectar al servidor de correo.',
        );
    }
}
