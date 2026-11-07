<?php

namespace App\Services\WijayaPay;

use Carbon\CarbonImmutable;
use JsonException;

final readonly class WijayaPayCallback
{
    public function __construct(
        public string $refId,
        public string $trxReference,
        public WijayaPayStatus $status,
        public ?string $paymentMethod,
        public ?int $totalDibayar,
        public ?int $totalFee,
        public ?int $amountReceived,
        public ?CarbonImmutable $updatedAt,
        public array $sanitizedPayload,
    ) {
        if ($refId === '' || $trxReference === '') {
            throw new WijayaPaySchemaException('callback');
        }
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $data = WijayaPayPayloadReader::dataObject($payload);
        $status = WijayaPayInvoice::status($payload, $data);

        $paymentMethod = WijayaPayPayloadReader::optionalString($data, 'payment_method', 64)
            ?? WijayaPayPayloadReader::optionalString($data, 'payment_methode', 64);
        if ($paymentMethod !== null
            && strcasecmp($paymentMethod, WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS) !== 0
        ) {
            throw new WijayaPaySchemaException('callback');
        }

        $totalDibayar = WijayaPayPayloadReader::optionalInteger($data, 'total_dibayar', allowZero: true);
        $totalFee = WijayaPayPayloadReader::optionalInteger($data, 'total_fee', allowZero: true);
        $amountReceived = WijayaPayPayloadReader::optionalInteger($data, 'amount_received', allowZero: true);

        if ($totalDibayar !== null && $totalFee !== null && $amountReceived !== null) {
            if ($amountReceived !== max(0, $totalDibayar - $totalFee)) {
                throw new WijayaPaySchemaException('callback');
            }
        }

        return new self(
            refId: WijayaPayPayloadReader::requiredString($data, 'ref_id', 255),
            trxReference: WijayaPayPayloadReader::requiredString($data, 'trx_reference', 255),
            status: $status,
            paymentMethod: $paymentMethod,
            totalDibayar: $totalDibayar,
            totalFee: $totalFee,
            amountReceived: $amountReceived,
            updatedAt: WijayaPayPayloadReader::optionalTimestamp($data, 'updated_at'),
            sanitizedPayload: WijayaPayPayloadSanitizer::sanitize($payload),
        );
    }

    public static function fromRawBody(string $rawBody): self
    {
        if (self::hasDuplicateKeys($rawBody)) {
            throw new WijayaPaySchemaException('callback');
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new WijayaPaySchemaException('callback', previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new WijayaPaySchemaException('callback');
        }

        return self::fromPayload($payload);
    }

    /**
     * Reject any object with a duplicated key. PHP's JSON decoder keeps the
     * last value silently, which would make a signed `ref_id` ambiguous.
     */
    public static function hasDuplicateKeys(string $json): bool
    {
        $length = strlen($json);
        $containers = [];
        $inString = false;
        $escaped = false;
        $string = '';

        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];

            if ($inString) {
                if ($escaped) {
                    $string .= $char;
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $string .= $char;
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                    $next = self::nextNonWhitespace($json, $index + 1);
                    if ($next === ':' && $containers !== [] && is_array($containers[array_key_last($containers)])) {
                        try {
                            $decoded = json_decode('"'.$string.'"', true, 2, JSON_THROW_ON_ERROR);
                        } catch (JsonException) {
                            $decoded = null;
                        }

                        if (is_string($decoded)) {
                            $top = array_key_last($containers);
                            if (isset($containers[$top][$decoded])) {
                                return true;
                            }

                            $containers[$top][$decoded] = true;
                        }
                    }

                    continue;
                }

                $string .= $char;

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $string = '';

                continue;
            }

            if ($char === '{') {
                $containers[] = [];

                continue;
            }

            if ($char === '[') {
                $containers[] = null;

                continue;
            }

            if ($char === '}' || $char === ']') {
                array_pop($containers);
            }
        }

        return false;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function isExpired(): bool
    {
        return $this->status->isExpired();
    }

    public function statusValue(): string
    {
        return $this->status->value;
    }

    public function merchantRef(): string
    {
        return $this->refId;
    }

    private static function nextNonWhitespace(string $json, int $offset): ?string
    {
        $length = strlen($json);
        for ($index = $offset; $index < $length; $index++) {
            if (! ctype_space($json[$index])) {
                return $json[$index];
            }
        }

        return null;
    }
}
