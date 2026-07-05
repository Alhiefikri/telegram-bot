<?php

namespace App\Services\Telegram;

use Illuminate\Support\Arr;

class TelegramUpdateShapeValidator
{
    public const TYPE_MESSAGE = 'message';

    public const TYPE_CALLBACK_QUERY = 'callback_query';

    public function supports(array $payload): bool
    {
        return $this->supportedType($payload) !== null;
    }

    public function supportedType(array $payload): ?string
    {
        $hasMessage = array_key_exists(self::TYPE_MESSAGE, $payload);
        $hasCallbackQuery = array_key_exists(self::TYPE_CALLBACK_QUERY, $payload);

        if ($hasMessage === $hasCallbackQuery) {
            return null;
        }

        if ($hasMessage) {
            $message = $payload[self::TYPE_MESSAGE];

            return is_array($message) && $this->isSupportedMessage($message)
                ? self::TYPE_MESSAGE
                : null;
        }

        $callbackQuery = $payload[self::TYPE_CALLBACK_QUERY];

        return is_array($callbackQuery) && $this->isSupportedCallbackQuery($callbackQuery)
            ? self::TYPE_CALLBACK_QUERY
            : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isSupportedMessage(array $message): bool
    {
        return $this->hasInteger($message, 'message_id', positive: true)
            && $this->hasInteger($message, 'from.id', positive: true)
            && $this->hasInteger($message, 'chat.id', nonZero: true)
            && $this->hasOptionalString($message, 'chat.type');
    }

    /**
     * @param  array<string, mixed>  $callbackQuery
     */
    private function isSupportedCallbackQuery(array $callbackQuery): bool
    {
        return $this->hasString($callbackQuery, 'id')
            && $this->hasInteger($callbackQuery, 'from.id', positive: true)
            && $this->hasInteger($callbackQuery, 'message.chat.id', nonZero: true)
            && $this->hasOptionalString($callbackQuery, 'message.chat.type')
            && $this->hasString($callbackQuery, 'data');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasInteger(array $payload, string $key, bool $positive = false, bool $nonZero = false): bool
    {
        $value = Arr::get($payload, $key);

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_int($value) && (! is_string($value) || preg_match('/^-?\d+$/', $value) !== 1)) {
            return false;
        }

        $integer = (int) $value;

        if ($positive && $integer < 1) {
            return false;
        }

        return ! $nonZero || $integer !== 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasString(array $payload, string $key): bool
    {
        $value = Arr::get($payload, $key);

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasOptionalString(array $payload, string $key): bool
    {
        $value = Arr::get($payload, $key);

        return $value === null || (is_string($value) && trim($value) !== '');
    }
}
