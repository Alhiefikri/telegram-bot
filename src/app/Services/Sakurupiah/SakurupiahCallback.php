<?php

namespace App\Services\Sakurupiah;

use JsonException;

final readonly class SakurupiahCallback
{
    public const EVENT_PAYMENT_STATUS = 'payment_status';

    public string $event;

    public string $trxId;

    public string $merchantRef;

    public SakurupiahTransactionStatus $status;

    public int $statusCode;

    /** @var array<mixed> */
    public array $sanitizedPayload;

    public function __construct(
        string $trxId,
        string $merchantRef,
        SakurupiahTransactionStatus $status,
        int $statusCode,
        string $event = self::EVENT_PAYMENT_STATUS,
        array $sanitizedPayload = [],
    ) {
        if ($event !== self::EVENT_PAYMENT_STATUS) {
            throw new SakurupiahSchemaException;
        }

        $this->event = $event;
        $this->trxId = self::text($trxId, 255);
        $this->merchantRef = self::text($merchantRef, 255);
        $this->status = $status;
        $this->statusCode = $statusCode;
        self::assertStatusCode($status, $statusCode);
        $this->sanitizedPayload = SakurupiahPayloadSanitizer::sanitize(array_replace(
            $sanitizedPayload,
            [
                'trx_id' => $this->trxId,
                'merchant_ref' => $this->merchantRef,
                'status' => $this->status->value,
                'status_kode' => $this->statusCode,
            ],
        ));
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, string $event = self::EVENT_PAYMENT_STATUS): self
    {
        return new self(
            trxId: SakurupiahPayloadReader::requiredString($payload, 'trx_id', 255),
            merchantRef: SakurupiahPayloadReader::requiredString($payload, 'merchant_ref', 255),
            status: SakurupiahTransactionStatus::fromProviderValue($payload['status'] ?? null),
            statusCode: self::statusCode($payload['status_kode'] ?? null),
            event: $event,
            sanitizedPayload: $payload,
        );
    }

    public static function fromRawBody(string $rawBody, string $event = self::EVENT_PAYMENT_STATUS): self
    {
        if (self::hasDuplicateCriticalKeys($rawBody)) {
            throw new SakurupiahSchemaException;
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new SakurupiahSchemaException;
        }

        return self::fromPayload($payload, $event);
    }

    public static function hasDuplicateCriticalKeys(string $json): bool
    {
        $critical = array_fill_keys(['trx_id', 'merchant_ref', 'status', 'status_kode'], true);
        $seen = [];
        $depth = 0;
        $length = strlen($json);
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
                    if ($depth === 1 && $next === ':') {
                        try {
                            $decoded = json_decode('"'.$string.'"', true, 2, JSON_THROW_ON_ERROR);
                        } catch (JsonException) {
                            $decoded = null;
                        }

                        if (is_string($decoded) && isset($critical[$decoded])) {
                            if (isset($seen[$decoded])) {
                                return true;
                            }

                            $seen[$decoded] = true;
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

            if ($char === '{' || $char === '[') {
                $depth++;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
            }
        }

        return false;
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

    public function statusValue(): string
    {
        return $this->status->value;
    }

    /** @return array<mixed> */
    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isExpired(): bool
    {
        return $this->status->isExpired();
    }

    private static function statusCode(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            throw new SakurupiahSchemaException;
        }

        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        if (strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new SakurupiahSchemaException;
        }

        $code = (int) $digits;

        return $negative ? -$code : $code;
    }

    private static function assertStatusCode(SakurupiahTransactionStatus $status, int $code): void
    {
        $allowed = match ($status) {
            SakurupiahTransactionStatus::Pending => [0],
            SakurupiahTransactionStatus::Successful => [1],
            SakurupiahTransactionStatus::Expired => self::expiredStatusCodes(),
        };

        if (! in_array($code, $allowed, true)) {
            throw new SakurupiahSchemaException;
        }
    }

    /** @return list<int> */
    private static function expiredStatusCodes(): array
    {
        $configured = config('services.sakurupiah.callback_expired_status_codes', [2]);
        if (! is_array($configured)) {
            return [2];
        }

        $codes = [];
        foreach ($configured as $code) {
            if (is_int($code)) {
                $codes[] = $code;
            }
        }

        return $codes === [] ? [2] : array_values(array_unique($codes));
    }

    private static function text(string $value, int $maxLength): string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new SakurupiahSchemaException;
        }

        return $value;
    }
}
