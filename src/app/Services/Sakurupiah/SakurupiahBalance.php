<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final readonly class SakurupiahBalance
{
    public SakurupiahAmount $amount;

    public ?string $currency;

    public function __construct(
        SakurupiahAmount|int|string $amount,
        ?string $currency = null,
    ) {
        try {
            $this->amount = $amount instanceof SakurupiahAmount
                ? $amount
                : SakurupiahAmount::from($amount, allowZero: true);
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }

        $this->currency = $currency === null ? null : self::text($currency, 16);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $data = $payload;
        if (array_key_exists('data', $payload)) {
            $rawData = $payload['data'];
            $data = is_array($rawData)
                ? (array_is_list($rawData)
                    ? SakurupiahPayloadReader::firstDataItem($payload)
                    : SakurupiahPayloadReader::object($rawData))
                : ['balance' => $rawData];
        } else {
            $data = SakurupiahPayloadReader::object($payload);
        }

        $amount = null;
        foreach (['balance', 'saldo', 'amount'] as $key) {
            if (array_key_exists($key, $data)) {
                $amount = $data[$key];
                break;
            }
        }

        if ($amount === null) {
            throw new SakurupiahSchemaException;
        }

        $currency = SakurupiahPayloadReader::optionalString($data, 'currency', 16);

        return new self($amount, $currency);
    }

    public function value(): int
    {
        return $this->amount->value();
    }

    private static function text(string $value, int $maxLength): string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Sakurupiah balance currency is invalid.');
        }

        return $value;
    }
}
