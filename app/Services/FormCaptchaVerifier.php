<?php

namespace App\Services;

use App\Models\Form;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class FormCaptchaVerifier
{
    public function verify(Form $form, ?string $token, ?string $remoteIp): bool
    {
        if (! (bool) data_get($form->settings, 'captcha_required', false)) {
            return false;
        }
        if (blank($token)) {
            throw ValidationException::withMessages(['captcha_token' => 'Captcha verification is required.']);
        }

        $secret = (string) config('services.turnstile.secret_key');
        if ($secret === '') {
            throw new ServiceUnavailableHttpException(null, 'Captcha verification is not configured.');
        }

        $response = Http::asForm()->acceptJson()->timeout(8)->retry(2, 150, throw: false)
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
                'idempotency_key' => (string) Str::uuid(),
            ], fn ($value) => $value !== null && $value !== ''));

        if (! $response->successful()) {
            throw new ServiceUnavailableHttpException(null, 'Captcha verification is temporarily unavailable.');
        }
        if ($response->json('success') !== true) {
            throw ValidationException::withMessages(['captcha_token' => 'Captcha verification failed.']);
        }

        $expectedHostname = data_get($form->settings, 'captcha_hostname');
        if (is_string($expectedHostname) && $expectedHostname !== ''
            && ! hash_equals(mb_strtolower($expectedHostname), mb_strtolower((string) $response->json('hostname')))) {
            throw ValidationException::withMessages(['captcha_token' => 'Captcha verification failed.']);
        }

        return true;
    }
}
