<?php

namespace App\Services;

use App\Models\Currency;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

final class CurrencyConversionService
{
    public function convert(string|int $amount, Currency $source, Currency $target): string
    {
        if ((int) $source->tenant_id !== (int) $target->tenant_id) {
            throw ValidationException::withMessages(['currency_id' => 'Currencies must belong to the same tenant.']);
        }
        if ((int) $source->id === (int) $target->id) {
            return Money::of($amount);
        }
        if (Money::compare((string) $source->exchange_rate, '0') <= 0 || Money::compare((string) $target->exchange_rate, '0') <= 0) {
            throw ValidationException::withMessages(['currency_id' => 'Currency exchange rates must be greater than zero.']);
        }

        return Money::multiply(Money::divide($amount, (string) $source->exchange_rate), (string) $target->exchange_rate);
    }
}
