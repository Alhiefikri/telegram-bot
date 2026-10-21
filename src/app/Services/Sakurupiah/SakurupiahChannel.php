<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final readonly class SakurupiahChannel
{
    public string $code;

    public string $name;

    public int $minimum;

    public int $maximum;

    public ?SakurupiahFee $fee;

    public ?string $percent;

    public string $type;

    public string $status;

    public ?SakurupiahChannelAddition $addition;

    public function __construct(
        string $code,
        string $name,
        int $minimum,
        int $maximum,
        string $type,
        string $status,
        ?SakurupiahFee $fee = null,
        ?string $percent = null,
        ?SakurupiahChannelAddition $addition = null,
    ) {
        $this->code = self::text($code, 'channel code', 64);
        $this->name = self::text($name, 'channel name', 255);
        if ($minimum < 1 || $maximum < $minimum) {
            throw new InvalidArgumentException('Sakurupiah channel amount limits are invalid.');
        }

        $this->minimum = $minimum;
        $this->maximum = $maximum;
        $this->type = self::text($type, 'channel type', 32);
        $this->status = self::text($status, 'channel status', 64);
        $this->fee = $fee;
        $this->percent = $percent === null ? null : self::text($percent, 'channel fee percentage', 32);
        $this->addition = $addition;
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $addition = null;
        if (array_key_exists('addition', $payload)) {
            $rawAddition = SakurupiahPayloadReader::object($payload['addition']);
            $addition = new SakurupiahChannelAddition(
                additionalFee: self::optionalFee($rawAddition, 'tambahan_biaya'),
                kind: SakurupiahPayloadReader::optionalString($rawAddition, 'jenis', 64),
                defaultExpiredHours: self::optionalExpiryHours($rawAddition),
                settlement: SakurupiahPayloadReader::optionalString($rawAddition, 'settlement', 64),
            );
        }

        $feeUnit = self::optionalFeeUnit($payload);
        $percent = self::optionalPercent($payload, $feeUnit);

        return new self(
            code: SakurupiahPayloadReader::requiredString($payload, 'kode', 64),
            name: SakurupiahPayloadReader::requiredString($payload, 'nama', 255),
            minimum: SakurupiahPayloadReader::requiredInteger($payload, 'minimal', allowZero: false),
            maximum: SakurupiahPayloadReader::requiredInteger($payload, 'maksimal', allowZero: false),
            type: SakurupiahPayloadReader::requiredString($payload, 'tipe', 32),
            status: SakurupiahPayloadReader::requiredString($payload, 'status', 64),
            fee: self::optionalFee($payload, 'biaya', $feeUnit === 'percent'),
            percent: $percent,
            addition: $addition,
        );
    }

    public function isActive(): bool
    {
        return in_array(strtolower($this->status), ['aktif', 'active', 'available', 'online', '1'], true);
    }

    public function isQris(): bool
    {
        return strtoupper($this->code) === SakurupiahCreateInvoice::METHOD_QRISC;
    }

    public function minimumAmount(): int
    {
        return $this->minimum;
    }

    public function maximumAmount(): int
    {
        return $this->maximum;
    }

    public function feeAmount(): ?int
    {
        return $this->fee?->fixedAmount();
    }

    /**
     * The provider sends the fee value in `biaya` and its unit in `percent`
     * (`Percent` or `Nominal`), rather than appending `%` to the value.
     *
     * @param  array<mixed>  $payload
     */
    private static function optionalFeeUnit(array $payload): ?string
    {
        $unit = SakurupiahPayloadReader::optionalString($payload, 'percent', 32);

        return $unit === null ? null : strtolower($unit);
    }

    /** @param array<mixed> $payload */
    private static function optionalPercent(array $payload, ?string $feeUnit): ?string
    {
        if ($feeUnit === null || $feeUnit === 'nominal') {
            return null;
        }

        if ($feeUnit === 'percent') {
            if (! array_key_exists('biaya', $payload)) {
                throw new SakurupiahSchemaException;
            }

            try {
                $fee = SakurupiahFee::from(self::normalizeFeeValue($payload['biaya'], percent: true));
                if ($fee === null || ! $fee->isPercentage()) {
                    throw new InvalidArgumentException('Sakurupiah percentage fee is required.');
                }
            } catch (InvalidArgumentException $exception) {
                throw new SakurupiahSchemaException(previous: $exception);
            }

            return $fee->percentage();
        }

        try {
            SakurupiahFee::from(rtrim($feeUnit, '%').'%');
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }

        return rtrim($feeUnit, '%');
    }

    /** @param array<mixed> $payload */
    private static function optionalExpiryHours(array $payload): int|float|null
    {
        $value = $payload['default_expired'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if ((! is_int($value) && ! is_float($value) && ! is_string($value))
            || preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', (string) $value) !== 1
        ) {
            throw new SakurupiahSchemaException;
        }

        $hours = (float) $value;
        if (! is_finite($hours) || $hours <= 0 || $hours >= PHP_INT_MAX) {
            throw new SakurupiahSchemaException;
        }

        return floor($hours) === $hours ? (int) $hours : $hours;
    }

    private static function normalizeFeeValue(mixed $value, bool $percent): mixed
    {
        if (! $percent || $value === null || $value === '') {
            return $value;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Sakurupiah fee must be a scalar numeric value.');
        }

        return str_ends_with((string) $value, '%') ? $value : (string) $value.'%';
    }

    /** @param array<mixed> $payload */
    private static function optionalFee(array $payload, string $key, bool $percent = false): ?SakurupiahFee
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        try {
            return SakurupiahFee::from(self::normalizeFeeValue($payload[$key], $percent));
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }
    }

    private static function text(string $value, string $label, int $maxLength): string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }

        return $value;
    }
}
