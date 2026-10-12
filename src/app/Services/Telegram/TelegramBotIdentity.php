<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramBotIdentity
{
    public function __construct(private readonly TelegramBotApiRequestFactory $requests) {}

    public function username(): ?string
    {
        $token = config('services.telegram.token');
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $key = 'telegram.bot-identity.v1.'.hash_hmac('sha256',
            config('services.telegram.api_base_url')."\0".$token,
            (string) config('app.key'),
        );

        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $this->validUsername($cached['username'] ?? null);
            }

            try {
                $username = $this->fetchUsername();
            } catch (Throwable) {
                $username = null;
            }
            Cache::put($key, ['username' => $username], $username === null ? 30 : 3600);

            return $username;
        } catch (Throwable) {
            // Transport errors can contain the token-bearing URL; never expose them.
            return null;
        }
    }

    public function startUrl(string $payload = 'landing'): ?string
    {
        $username = $this->username();

        return $username === null ? null : 'https://t.me/'.$username.'?start='.rawurlencode($payload);
    }

    private function fetchUsername(): ?string
    {
        $response = $this->requests->request()
            ->withoutRedirecting()
            ->post($this->requests->url('getMe'));
        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || ($data['ok'] ?? false) !== true
            || ($data['result']['is_bot'] ?? false) !== true
            || ! is_int($data['result']['id'] ?? null) || $data['result']['id'] <= 0) {
            return null;
        }

        return $this->validUsername($data['result']['username'] ?? null);
    }

    private function validUsername(mixed $username): ?string
    {
        return is_string($username) && preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/', $username) === 1
            ? $username : null;
    }
}
