<?php

namespace App\Services\Telegram;

final class TelegramStarsUpdate
{
    public const PRE_CHECKOUT = 'pre_checkout_query';

    public const SUCCESS = 'successful_payment';

    public const REFUND = 'refunded_payment';

    public static function kind(array $payload): ?string
    {
        if (array_key_exists(self::PRE_CHECKOUT, $payload)) {
            return self::PRE_CHECKOUT;
        }

        foreach ([self::SUCCESS, self::REFUND] as $kind) {
            if (is_array($payload['message'] ?? null) && array_key_exists($kind, $payload['message'])) {
                return $kind;
            }
        }

        return null;
    }

    public static function minimize(array $payload, string $kind): ?array
    {
        if (array_key_exists('callback_query', $payload) || ! array_key_exists('update_id', $payload)) {
            return null;
        }

        $base = ['update_id' => $payload['update_id']];
        if ($kind === self::PRE_CHECKOUT) {
            $query = $payload[$kind] ?? null;
            $actor = $query['from']['id'] ?? null;
            if (array_key_exists('message', $payload) || ! is_array($query)
                || ! self::moneyShape($query) || ! self::opaque($query['id'] ?? null, 256)
                || ! is_int($actor) || $actor < 1) {
                return null;
            }

            return $base + [$kind => [
                'id' => $query['id'], 'currency' => $query['currency'], 'total_amount' => $query['total_amount'],
                'invoice_payload' => $query['invoice_payload'], 'from' => ['id' => $actor],
            ]];
        }

        $message = $payload['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }
        $event = $message[$kind] ?? null;
        $actor = $message['from']['id'] ?? null;
        $chat = $message['chat']['id'] ?? null;
        $chatType = $message['chat']['type'] ?? null;
        $messageId = $message['message_id'] ?? null;
        if ((array_key_exists(self::SUCCESS, $message) && array_key_exists(self::REFUND, $message))
            || ! is_array($event) || ! self::moneyShape($event)
            || ! self::opaque($event['telegram_payment_charge_id'] ?? null, 256)
            || ! is_int($messageId) || $messageId < 1 || ! is_int($chat) || $chat === 0
            || ! in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)
            || (($actor !== null || $kind === self::SUCCESS) && (! is_int($actor) || $actor < 1))) {
            return null;
        }

        return $base + ['message' => [
            'message_id' => $messageId,
            'chat' => ['id' => $chat, 'type' => $chatType],
            ...($actor === null ? [] : ['from' => ['id' => $actor]]),
            $kind => [
                'currency' => $event['currency'], 'total_amount' => $event['total_amount'],
                'invoice_payload' => $event['invoice_payload'], 'telegram_payment_charge_id' => $event['telegram_payment_charge_id'],
            ]
                + ($kind === self::SUCCESS && array_intersect_key($event, array_flip([
                    'is_recurring', 'is_first_recurring', 'subscription_expiration_date',
                ])) !== [] ? ['is_recurring' => true] : []),
        ]];
    }

    private static function moneyShape(array $event): bool
    {
        $currency = $event['currency'] ?? null;
        $amount = $event['total_amount'] ?? null;

        return is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) === 1
            && is_int($amount) && $amount > 0
            && self::opaque($event['invoice_payload'] ?? null, 128);
    }

    private static function opaque(mixed $value, int $max): bool
    {
        return is_string($value) && strlen($value) <= $max && trim($value) !== '' && ! str_contains($value, "\0");
    }
}
