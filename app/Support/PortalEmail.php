<?php

namespace App\Support;

final class PortalEmail
{
    public static function normalize(?string $email): ?string
    {
        $value = mb_strtolower(trim((string) $email));

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1)
            .str_repeat('*', max(3, mb_strlen($local) - 1))
            .'@'.$domain;
    }
}
