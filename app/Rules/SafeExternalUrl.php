<?php

namespace App\Rules;

use App\Support\UrlSafety;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! UrlSafety::isPublic($value)) {
            $fail('The URL must use HTTP(S) and resolve only to a public host.');
        }
    }
}
