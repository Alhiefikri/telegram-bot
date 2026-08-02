<?php

namespace App\Services\Pakasir;

use UnexpectedValueException;

final readonly class QrisEmvPayload
{
    /** @param array<string, string> $fields */
    private function __construct(private array $fields, private int $amount) {}

    public static function parse(string $payload): self
    {
        if (! preg_match('/6304[0-9A-Fa-f]{4}\z/', $payload)) {
            throw new UnexpectedValueException('QRIS payload is missing a terminal CRC.');
        }

        $providedCrc = strtoupper(substr($payload, -4));
        $crcInput = substr($payload, 0, -4);
        if (! hash_equals(self::crc16($crcInput), $providedCrc)) {
            throw new UnexpectedValueException('QRIS CRC mismatch.');
        }

        $fields = [];
        $offset = 0;
        $payloadLength = strlen($payload);
        while ($offset < $payloadLength) {
            if ($offset + 4 > $payloadLength) {
                throw new UnexpectedValueException('QRIS payload has an invalid TLV structure.');
            }

            $tag = substr($payload, $offset, 2);
            $encodedLength = substr($payload, $offset + 2, 2);
            if (! ctype_digit($tag) || ! ctype_digit($encodedLength)) {
                throw new UnexpectedValueException('QRIS payload has an invalid TLV structure.');
            }

            $length = (int) $encodedLength;
            $valueOffset = $offset + 4;
            if ($valueOffset + $length > $payloadLength || array_key_exists($tag, $fields)) {
                throw new UnexpectedValueException('QRIS payload has an invalid TLV structure.');
            }

            $fields[$tag] = substr($payload, $valueOffset, $length);
            $offset = $valueOffset + $length;
        }

        if (($fields['63'] ?? null) !== $providedCrc) {
            throw new UnexpectedValueException('QRIS payload has an invalid TLV structure.');
        }

        $amountValue = $fields['54'] ?? null;
        if ($amountValue === null) {
            throw new UnexpectedValueException('QRIS amount tag is required.');
        }
        if (! preg_match('/\A([1-9][0-9]*)(?:\.00)?\z/', $amountValue, $matches)) {
            throw new UnexpectedValueException('QRIS amount must be an exact rupiah value.');
        }

        return new self($fields, (int) $matches[1]);
    }

    public function currency(): string
    {
        return $this->fields['53'] ?? '';
    }

    public function amountRupiah(): int
    {
        return $this->amount;
    }

    public function assertMatchesIdrAmount(int $amount): void
    {
        if ($this->currency() !== '360') {
            throw new UnexpectedValueException('QRIS currency must be IDR.');
        }
        if ($this->amount !== $amount) {
            throw new UnexpectedValueException('QRIS encoded amount mismatch.');
        }
    }

    private static function crc16(string $value): string
    {
        $crc = 0xFFFF;
        foreach (str_split($value) as $character) {
            $crc ^= ord($character) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
