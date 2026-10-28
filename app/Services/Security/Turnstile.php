<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class Turnstile
{
    public const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function hostnames(): array
    {
        $hosts = config('turnstile.hostnames') ?: [parse_url(config('app.url'), PHP_URL_HOST)];

        return array_values(array_unique(array_filter(array_map(fn ($host) => is_string($host) ? strtolower(rtrim(trim($host), '.')) : '', $hosts))));
    }

    public function configured(): bool
    {
        foreach (['site_key', 'secret_key'] as $key) {
            $value = config('turnstile.'.$key);
            if (! is_string($value) || strlen($value) < 10 || strlen($value) > 256 || preg_match('/\s/', $value)) {
                return false;
            }
            if (app()->environment('production') && preg_match('/\A[123]x0+/', $value)) {
                return false;
            }
        }

        return $this->hostnames() !== [];
    }

    public function validate(#[\SensitiveParameter] mixed $token, string $action): void
    {
        if (! config('turnstile.enabled')) {
            return;
        }
        if (! $this->configured()) {
            $this->fail('unavailable');
        }
        if (! is_string($token) || trim($token) === '' || strlen($token) > 2048) {
            $this->fail('required');
        }
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(2)->timeout(5)->withOptions(['allow_redirects' => false])->post(self::ENDPOINT, [
                'secret' => config('turnstile.secret_key'), 'response' => $token,
            ]);
            $data = $response->json();
        } catch (\Throwable) {
            $this->fail('unavailable');
        }
        if (! $response->successful() || ! is_array($data)) {
            $this->fail('unavailable');
        }
        if (($data['success'] ?? null) !== true || ! is_string($data['hostname'] ?? null) || ! is_string($data['action'] ?? null)
            || ! in_array(strtolower(rtrim($data['hostname'], '.')), $this->hostnames(), true) || ! hash_equals($action, $data['action'])) {
            $this->fail('failed');
        }
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages(['turnstile' => __('turnstile.'.$reason)]);
    }
}
