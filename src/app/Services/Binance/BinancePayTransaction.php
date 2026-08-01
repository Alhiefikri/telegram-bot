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
        private string $receiverAccountId,
        private string $receiverBinanceId,
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

        if ($transactionId === '' || $amount === '' || $currency === '' || ! is_numeric($transactionTime)) {
            return null;
        }

        $amountMicro = BinanceUsdtAmount::toMicro($amount);
        if ($amountMicro === null) {
            return null;
        }

        $receiver = is_array($payload['receiverInfo'] ?? null) ? $payload['receiverInfo'] : [];
        $receiverAccountId = self::stringValue($receiver['accountId'] ?? null);
        $receiverBinanceId = self::stringValue($receiver['binanceId'] ?? null);

        return new self(
            orderId: $orderId,
            transactionId: $transactionId,
            amountMicro: $amountMicro,
            currency: $currency,
            transactionTimeMs: (int) $transactionTime,
            orderType: self::stringValue($payload['orderType'] ?? null),
            note: self::stringValue($payload['note'] ?? null),
            receiverAccountId: $receiverAccountId,
            receiverBinanceId: $receiverBinanceId,
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

    public function matchesSubmittedOrderId(string $submitted): string|false
    {
        if ($this->orderId !== '' && hash_equals($this->orderId, $submitted)) {
            return 'order_id';
        }

        if ($this->transactionId !== '' && hash_equals($this->transactionId, $submitted)) {
            return 'transaction_id_compatibility';
        }

        return false;
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

    public function isIncomingTo(string $merchantPayId): bool
    {
        return $this->isIncomingToConfiguredReceiver($merchantPayId, null);
    }

    public function isIncomingToConfiguredReceiver(?string $merchantPayId, ?string $merchantUid): bool
    {
        if ($this->amountMicro <= 0) {
            return false;
        }

        $payIdMatch = filled($merchantPayId)
            && $this->receiverAccountId !== ''
            && hash_equals($this->receiverAccountId, trim($merchantPayId));
        $uidMatch = filled($merchantUid)
            && $this->receiverBinanceId !== ''
            && hash_equals($this->receiverBinanceId, trim($merchantUid));

        return $payIdMatch || $uidMatch;
    }

    /** @return array<string, mixed> */
    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    /** @return array<string, mixed> */
    private static function sanitize(array $payload): array
    {
        $receiver = is_array($payload['receiverInfo'] ?? null) ? $payload['receiverInfo'] : [];

        return array_filter([
            'orderId' => self::maskIdentifier(self::stringValue($payload['orderId'] ?? null)),
            'transactionId' => self::maskIdentifier(self::stringValue($payload['transactionId'] ?? null)),
            'orderType' => self::stringValue($payload['orderType'] ?? null),
            'amount' => self::stringValue($payload['amount'] ?? null),
            'currency' => self::stringValue($payload['currency'] ?? null),
            'transactionTime' => is_numeric($payload['transactionTime'] ?? null) ? (int) $payload['transactionTime'] : null,
            'receiverBinanceId' => is_scalar($receiver['binanceId'] ?? null) ? self::maskIdentifier(self::stringValue($receiver['binanceId'])) : null,
            'receiverAccountId' => is_scalar($receiver['accountId'] ?? null) ? self::maskIdentifier(self::stringValue($receiver['accountId'])) : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function maskIdentifier(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '•')) {
            return $value;
        }

        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 4).'••••'.substr($value, -4);
    }
}
