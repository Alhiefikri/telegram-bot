<?php

namespace App\Services\Telegram;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use JsonException;

class TelegramMiniAppAuthenticator
{
    public function __construct(private readonly int $maxAgeSeconds = 1800) {}

    /**
     * @return array{id:int,first_name:?string,last_name:?string,username:?string,name:string}
     */
    public function authenticate(?string $initData): array
    {
        $token = (string) config('services.telegram.token', '');

        if ($token === '' || blank($initData)) {
            abort(401, 'Telegram authentication is not configured.');
        }

        parse_str($initData, $data);

        $hash = Arr::pull($data, 'hash');
        $authDate = $data['auth_date'] ?? null;
        $userJson = $data['user'] ?? null;

        if (! is_string($hash) || $hash === '' || ! is_numeric($authDate) || ! is_string($userJson) || $userJson === '') {
            abort(401, 'Invalid Telegram init data.');
        }

        if (Carbon::createFromTimestamp((int) $authDate)->lt(now()->subSeconds($this->maxAgeSeconds))) {
            abort(401, 'Telegram init data has expired.');
        }

        ksort($data, SORT_STRING);
        $dataCheckString = collect($data)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($expectedHash, $hash)) {
            abort(401, 'Invalid Telegram init data signature.');
        }

        try {
            $user = json_decode($userJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(401, 'Invalid Telegram user data.');
        }

        if (! is_array($user) || ! isset($user['id']) || ! is_numeric($user['id'])) {
            abort(401, 'Invalid Telegram user data.');
        }

        $firstName = isset($user['first_name']) ? (string) $user['first_name'] : null;
        $lastName = isset($user['last_name']) ? (string) $user['last_name'] : null;

        return [
            'id' => (int) $user['id'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => isset($user['username']) ? (string) $user['username'] : null,
            'name' => trim(implode(' ', array_filter([$firstName, $lastName]))) ?: 'Telegram Admin',
        ];
    }

    public function isAdmin(array $telegramUser): bool
    {
        $adminId = config('services.telegram.admin_user_id') ?: config('services.telegram.admin_telegram_chat_id');

        if (blank($adminId) || ! isset($telegramUser['id'])) {
            return false;
        }

        return hash_equals((string) $adminId, (string) $telegramUser['id']);
    }

    public function authenticateAdmin(?string $initData): array
    {
        $user = $this->authenticate($initData);

        if (! $this->isAdmin($user)) {
            abort(403, 'Access denied.');
        }

        return $user;
    }
}
