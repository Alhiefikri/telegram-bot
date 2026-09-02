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
                defaultExpiredHours: SakurupiahPayloadReader::optionalInteger($rawAddition, 'default_expired', allowZero: false),
                settlement: SakurupiahPayloadReader::optionalString($rawAddition, 'settlement', 64),
            );
        }

        $percent = self::optionalPercent($payload);

        return new self(
            code: SakurupiahPayloadReader::requiredString($payload, 'kode', 64),
            name: SakurupiahPayloadReader::requiredString($payload, 'nama', 255),
            minimum: SakurupiahPayloadReader::requiredInteger($payload, 'minimal', allowZero: false),
            maximum: SakurupiahPayloadReader::requiredInteger($payload, 'maksimal', allowZero: false),
            type: SakurupiahPayloadReader::requiredString($payload, 'tipe', 32),
            status: SakurupiahPayloadReader::requiredString($payload, 'status', 64),
            fee: self::optionalFee($payload, 'biaya'),
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
        return strtoupper($this->code) === 'QRIS';
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

    /** @param array<mixed> $payload */
    private static function optionalPercent(array $payload): ?string
    {
        $percent = SakurupiahPayloadReader::optionalString($payload, 'percent', 32);
        if ($percent === null) {
            return null;
        }

        try {
            SakurupiahFee::from(rtrim($percent, '%').'%');
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }

        return $percent;
    }

    private static function optionalFee(array $payload, string $key): ?SakurupiahFee
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        try {
            return SakurupiahFee::from($payload[$key]);
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
