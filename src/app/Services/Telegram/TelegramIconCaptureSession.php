<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

/**
 * Pending "send me this emoji" state for one admin, keyed by Telegram identity
 * so the next inbound message can be matched to the slot that is waiting for
 * its custom emoji — a catalog brand or a named navigation button.
 */
class TelegramIconCaptureSession
{
    public const KIND_BRAND = 'brand';

    public const KIND_MENU = 'menu';

    private const TTL_MINUTES = 5;

    /**
     * @return array{kind: string, target_id: int, message_id: int|null, expires_at: int}
     */
    public function startBrand(Customer $customer, int $brandId, ?int $messageId): array
    {
        return $this->start($customer, self::KIND_BRAND, $brandId, $messageId);
    }

    /**
     * @return array{kind: string, target_id: int, message_id: int|null, expires_at: int}
     */
    public function startMenu(Customer $customer, int $iconId, ?int $messageId): array
    {
        return $this->start($customer, self::KIND_MENU, $iconId, $messageId);
    }

    /**
     * @return array{kind: string, target_id: int, message_id: int|null, expires_at: int}|null
     */
    public function get(Customer $customer): ?array
    {
        $state = Cache::get($this->key($customer));
        if (! is_array($state)) {
            return null;
        }

        $kind = $state['kind'] ?? null;
        $targetId = $state['target_id'] ?? null;
        $expiresAt = $state['expires_at'] ?? null;
        if (! is_string($kind) || ! is_int($targetId) || ! is_int($expiresAt) || $expiresAt < now()->getTimestamp()) {
            $this->forget($customer);

            return null;
        }

        $messageId = $state['message_id'] ?? null;

        return [
            'kind' => $kind,
            'target_id' => $targetId,
            'message_id' => is_int($messageId) ? $messageId : null,
            'expires_at' => $expiresAt,
        ];
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }

    /**
     * @return array{kind: string, target_id: int, message_id: int|null, expires_at: int}
     */
    private function start(Customer $customer, string $kind, int $targetId, ?int $messageId): array
    {
        $state = [
            'kind' => $kind,
            'target_id' => $targetId,
            'message_id' => $messageId,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ];

        Cache::put($this->key($customer), $state, now()->addMinutes(self::TTL_MINUTES));

        return $state;
    }

    private function key(Customer $customer): string
    {
        return 'telegram:icon-capture:'.$customer->telegram_id;
    }
}
