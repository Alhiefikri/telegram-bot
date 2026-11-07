<?php

namespace App\Services\WijayaPay;

use InvalidArgumentException;

final readonly class WijayaPayChannel
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $group,
        public string $status,
        public int $feeAmount,
        public int $feePercentBps,
        public int $minimumAmount,
        public int $maximumAmount,
        public ?string $feePayer,
    ) {
        foreach ([$code, $name, $status] as $value) {
            if ($value === '' || trim($value) !== $value) {
                throw new InvalidArgumentException('WijayaPay channel text is invalid.');
            }
        }

        foreach ([$feeAmount, $feePercentBps, $minimumAmount, $maximumAmount] as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('WijayaPay channel amount is invalid.');
            }
        }

        if ($maximumAmount > 0 && $minimumAmount > $maximumAmount) {
            throw new InvalidArgumentException('WijayaPay channel limits are invalid.');
        }
    }

    /** @param array<mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $feePercent = WijayaPayPayloadReader::optionalString($payload, 'fee_percent', 32);
        $feePayer = WijayaPayPayloadReader::optionalString($payload, 'type_fee', 32);

        return new self(
            code: WijayaPayPayloadReader::requiredString($payload, 'code', 64),
            name: WijayaPayPayloadReader::requiredString($payload, 'name', 128),
            group: WijayaPayPayloadReader::optionalString($payload, 'group', 64),
            status: WijayaPayPayloadReader::requiredString($payload, 'status', 32),
            feeAmount: WijayaPayPayloadReader::optionalInteger($payload, 'fee_amount', allowZero: true) ?? 0,
            feePercentBps: self::percentToBps($feePercent),
            minimumAmount: WijayaPayPayloadReader::optionalInteger($payload, 'min_trx', allowZero: true) ?? 0,
            maximumAmount: WijayaPayPayloadReader::optionalInteger($payload, 'max_trx', allowZero: true) ?? 0,
            feePayer: $feePayer === null ? null : strtolower($feePayer),
        );
    }

    public function isQris(): bool
    {
        return strtoupper($this->code) === 'QRIS';
    }

    public function isActive(): bool
    {
        return in_array(strtolower($this->status), ['active', 'aktif', 'enabled'], true);
    }

    public function minimumAmount(): int
    {
        return $this->minimumAmount;
    }

    public function maximumAmount(): int
    {
        return $this->maximumAmount;
    }

    private static function percentToBps(?string $value): int
    {
        if ($value === null) {
            return 0;
        }

        $value = trim($value);
        if (preg_match('/^\d+(?:\.\d{1,4})?$/', $value) !== 1) {
            throw new WijayaPaySchemaException;
        }

        $parts = explode('.', $value, 2);
        if (strlen($parts[0]) > 9) {
            throw new WijayaPaySchemaException;
        }
        $whole = (int) $parts[0];
        $fraction = isset($parts[1]) ? str_pad(substr($parts[1], 0, 4), 4, '0') : '0000';

        return min(PHP_INT_MAX, $whole * 100 + (int) substr($fraction, 0, 2));
    }
}
