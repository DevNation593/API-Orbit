<?php

namespace App\Support;

final class UrlSafety
{
    public static function isPublic(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === '') {
            return false;
        }
        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_contains($host, '@')
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
