<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived TXT confirmation state bound to the exact Customer, Telegram
 * chat, Order, and a random opaque single-use nonce. Cache inspection must
 * never reveal credentials, OTP URLs, or rendered file content.
 */
final class TelegramReceiptTxtConfirmationSession
{
    public const TTL_MINUTES = 10;

    /** @var list<string> */
    private const REQUIRED_KEYS = ['nonce', 'customerId', 'telegramChatId', 'orderId', 'locale'];

    public function start(Customer $customer, string $chatId, string $orderId, string $locale): array
    {
        $confirmation = [
            'nonce' => bin2hex(random_bytes(16)),
            'customerId' => (int) $customer->getKey(),
            'telegramChatId' => $chatId,
            'orderId' => $orderId,
            'locale' => $locale,
        ];

        $lock = Cache::lock($this->lockKey($chatId, $orderId), 10);
        try {
            $lock->block(5);
            $expiresAt = now()->addMinutes(self::TTL_MINUTES);
            Cache::put($this->key($chatId, $orderId), $confirmation, $expiresAt);
            Cache::put($this->nonceKey($chatId, $customer->getKey(), $confirmation['nonce']), $orderId, $expiresAt);
        } finally {
            $lock->release();
        }

        return $confirmation;
    }

    /**
     * Resolve a nonce through its short-lived, customer-bound index. This
     * avoids scanning every paid Order for a Customer on the normal callback
     * path. A null result is deliberately indistinguishable from a stale or
     * unknown nonce.
     */
    public function orderIdForNonce(Customer $customer, string $chatId, string $nonce): ?string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            return null;
        }

        $orderId = Cache::get($this->nonceKey($chatId, $customer->getKey(), $nonce));
        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        $confirmation = $this->state($chatId, $orderId);
        if ($confirmation === null
            || (int) ($confirmation['customerId'] ?? 0) !== (int) $customer->getKey()
            || (string) ($confirmation['telegramChatId'] ?? '') !== $chatId
            || ! hash_equals((string) ($confirmation['nonce'] ?? ''), $nonce)) {
            Cache::forget($this->nonceKey($chatId, $customer->getKey(), $nonce));

            return null;
        }

        return $orderId;
    }

    /**
     * Validate a confirmation bound to this Customer, exact Telegram chat,
     * and Order. This is a non-destructive lookup: a failed validation must
     * not invalidate the live confirmation for that chat and Order.
     */
    public function boundConfirmation(Customer $customer, string $chatId, string $orderId, string $nonce): ?array
    {
        $confirmation = $this->state($chatId, $orderId);
        if ($confirmation === null) {
            return null;
        }

        if ((int) ($confirmation['customerId'] ?? 0) !== (int) $customer->getKey()
            || (string) ($confirmation['telegramChatId'] ?? '') !== $chatId
            || ! hash_equals((string) $confirmation['nonce'], $nonce)) {
            return null;
        }

        return $confirmation;
    }

    /**
     * Atomically consume a confirmation once for a Send File or Cancel
     * action. Replayed, expired, cross-customer, and cross-chat tokens all
     * return null with no effect and no credential lookup.
     */
    public function consume(Customer $customer, string $chatId, string $orderId, string $nonce): ?array
    {
        try {
            return Cache::lock($this->lockKey($chatId, $orderId), 10)
                ->block(5, function () use ($customer, $chatId, $orderId, $nonce): ?array {
                    $confirmation = $this->boundConfirmation($customer, $chatId, $orderId, $nonce);
                    if ($confirmation === null) {
                        return null;
                    }

                    $this->forget($chatId, $orderId);

                    return $confirmation;
                });
        } catch (\Throwable) {
            return null;
        }
    }

    public function forget(string $chatId, string $orderId): void
    {
        $confirmation = Cache::get($this->key($chatId, $orderId));
        Cache::forget($this->key($chatId, $orderId));

        if (is_array($confirmation)
            && isset($confirmation['customerId'], $confirmation['nonce'])
            && is_int($confirmation['customerId'])
            && is_string($confirmation['nonce'])) {
            Cache::forget($this->nonceKey($chatId, $confirmation['customerId'], $confirmation['nonce']));
        }
    }

    private function state(string $chatId, string $orderId): ?array
    {
        $confirmation = Cache::get($this->key($chatId, $orderId));
        if (! is_array($confirmation)) {
            return null;
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (! isset($confirmation[$key])) {
                return null;
            }
        }
        if (! is_string($confirmation['nonce'])
            || preg_match('/\A[a-f0-9]{32}\z/D', $confirmation['nonce']) !== 1
            || ! is_int($confirmation['customerId'])
            || $confirmation['customerId'] < 1
            || ! is_string($confirmation['telegramChatId'])
            || $confirmation['telegramChatId'] === ''
            || ! is_string($confirmation['orderId'])
            || $confirmation['orderId'] === ''
            || ! is_string($confirmation['locale'])
            || ! in_array($confirmation['locale'], TelegramTranslationCatalog::SUPPORTED_LOCALES, true)) {
            return null;
        }

        return $confirmation;
    }

    private function key(string $chatId, string $orderId): string
    {
        return 'telegram:receipt-txt-confirmation:'.$chatId.':'.$orderId;
    }

    private function lockKey(string $chatId, string $orderId): string
    {
        return $this->key($chatId, $orderId).':lock';
    }

    private function nonceKey(string $chatId, int|string $customerId, string $nonce): string
    {
        return 'telegram:receipt-txt-confirmation-nonce:'.$chatId.':'.$customerId.':'.$nonce;
    }
}
