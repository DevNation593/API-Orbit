<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PortalToken
{
    public static function issue(): string
    {
        return Str::random(64);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
