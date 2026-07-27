<?php

namespace App\Services\Binance;

final readonly class BinancePayTransaction
{
    private function __construct(
        private string $orderId,
        private string $transactionId,
        private int $amountMicro,
        private string $currency,
        private int $transactionTimeMs,
        private string $orderType,
        private string $note,
        private array $sanitizedPayload,
    ) {}

    public static function fromPayload(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $orderId = self::stringValue($payload['orderId'] ?? null);
        $transactionId = self::stringValue($payload['transactionId'] ?? null);
        $amount = self::stringValue($payload['amount'] ?? null);
        $currency = strtoupper(self::stringValue($payload['currency'] ?? null));
        $transactionTime = $payload['transactionTime'] ?? null;

        if ($orderId === '' || $transactionId === '' || $amount === '' || $currency === '' || ! is_numeric($transactionTime)) {
            return null;
        }

        $amountMicro = BinanceUsdtAmount::toMicro($amount);
        if ($amountMicro === null) {
            return null;
        }

        return new self(
            orderId: $orderId,
            transactionId: $transactionId,
            amountMicro: $amountMicro,
            currency: $currency,
            transactionTimeMs: (int) $transactionTime,
            orderType: self::stringValue($payload['orderType'] ?? null),
            note: self::stringValue($payload['note'] ?? null),
            sanitizedPayload: self::sanitize($payload),
        );
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function transactionId(): string
    {
        return $this->transactionId;
    }

    public function amountMicro(): int
    {
        return $this->amountMicro;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function transactionTimeMs(): int
    {
        return $this->transactionTimeMs;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function isIncoming(): bool
    {
        return $this->amountMicro > 0;
    }

    /** @return array<string, mixed> */
    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    /** @return array<string, mixed> */
    private static function sanitize(array $payload): array
    {
        $payer = is_array($payload['payerInfo'] ?? null) ? $payload['payerInfo'] : [];

        return array_filter([
            'orderId' => self::stringValue($payload['orderId'] ?? null),
            'transactionId' => self::stringValue($payload['transactionId'] ?? null),
            'orderType' => self::stringValue($payload['orderType'] ?? null),
            'amount' => self::stringValue($payload['amount'] ?? null),
            'currency' => self::stringValue($payload['currency'] ?? null),
            'transactionTime' => is_numeric($payload['transactionTime'] ?? null) ? (int) $payload['transactionTime'] : null,
            'note' => self::stringValue($payload['note'] ?? null),
            'payerName' => self::stringValue($payer['name'] ?? null),
            'payerBinanceId' => is_numeric($payer['binanceId'] ?? null) ? (string) $payer['binanceId'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
