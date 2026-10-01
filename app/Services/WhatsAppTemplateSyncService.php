<?php

namespace App\Services;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WhatsAppTemplateSyncService
{
    /** @return array{synced: int, pages: int} */
    public function sync(WhatsAppAccount $account): array
    {
        $account->loadMissing('integration');
        $integration = $account->integration;
        $token = (string) data_get($integration?->credentials, 'access_token');
        $version = (string) data_get($integration?->settings, 'api_version');
        $businessId = (string) ($account->business_account_id ?: data_get($integration?->settings, 'business_account_id'));
        if ($account->status !== 'active' || $integration?->status !== 'active' || $token === '' || $businessId === '') {
            throw new RuntimeException('The WhatsApp Business account is not connected or has no business account id.');
        }
        $url = "https://graph.facebook.com/{$version}/{$businessId}/message_templates";
        $params = [
            'fields' => 'id,name,language,status,category,components',
            'limit' => 100,
        ];
        $synced = 0;
        $pages = 0;

        do {
            $response = Http::acceptJson()->withToken($token)->timeout(30)->connectTimeout(10)->get($url, $params);
            if (! $response->successful()) {
                throw new RuntimeException('WhatsApp template synchronization failed with HTTP '.$response->status().'.');
            }
            $pages++;
            foreach ((array) $response->json('data', []) as $template) {
                if (! is_array($template)) {
                    continue;
                }
                $name = trim((string) ($template['name'] ?? ''));
                $language = trim((string) ($template['language'] ?? ''));
                $components = is_array($template['components'] ?? null) ? $template['components'] : [];
                if ($name === '' || $language === '' || strlen(json_encode($components) ?: '') > 1_000_000) {
                    continue;
                }
                WhatsAppTemplate::updateOrCreate([
                    'whatsapp_account_id' => $account->id,
                    'name' => mb_substr($name, 0, 190),
                    'language' => mb_substr($language, 0, 20),
                ], [
                    'external_id' => mb_substr((string) ($template['id'] ?? ''), 0, 190) ?: null,
                    'category' => mb_substr(mb_strtolower((string) ($template['category'] ?? '')), 0, 50) ?: null,
                    'status' => mb_substr(mb_strtolower((string) ($template['status'] ?? 'pending')), 0, 30),
                    'components' => $components,
                    'synced_at' => now(),
                ]);
                $synced++;
            }
            $next = $response->json('paging.next');
            $url = is_string($next) && $next !== '' ? $this->trustedNextUrl($next, $version) : '';
            $params = [];
        } while ($url !== '' && $pages < 10);

        $account->update(['last_synced_at' => now()]);
        $integration->update(['last_synced_at' => now()]);

        return ['synced' => $synced, 'pages' => $pages];
    }

    private function trustedNextUrl(string $url, string $version): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https'
            || mb_strtolower((string) ($parts['host'] ?? '')) !== 'graph.facebook.com'
            || ! str_starts_with((string) ($parts['path'] ?? ''), '/'.$version.'/')) {
            throw new RuntimeException('WhatsApp returned an invalid pagination URL.');
        }

        return $url;
    }
}
