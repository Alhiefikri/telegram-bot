<?php

namespace App\Services\Sakurupiah;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SakurupiahStatus
{
    public string $trxId;

    public ?string $merchantRef;

    public ?string $paymentCode;

    public SakurupiahTransactionStatus $status;

    public ?SakurupiahAmount $amount;

    public ?CarbonImmutable $occurredAt;

    public ?CarbonImmutable $expiresAt;

    /** @var array<mixed> */
    public array $sanitizedPayload;

    public function __construct(
        string $trxId,
        SakurupiahTransactionStatus $status,
        ?string $merchantRef = null,
        ?string $paymentCode = null,
        ?SakurupiahAmount $amount = null,
        ?CarbonImmutable $occurredAt = null,
        ?CarbonImmutable $expiresAt = null,
        array $sanitizedPayload = [],
    ) {
        $this->trxId = self::text($trxId, 'transaction ID', 255);
        $this->status = $status;
        $this->merchantRef = $merchantRef === null ? null : self::text($merchantRef, 'merchant reference', 255);
        $this->paymentCode = $paymentCode === null ? null : self::text($paymentCode, 'payment code', 64);
        $this->amount = $amount;
        $this->occurredAt = $occurredAt;
        $this->expiresAt = $expiresAt;
        $this->sanitizedPayload = SakurupiahPayloadSanitizer::sanitize($sanitizedPayload);
    }

    /**
     * Accept either a response envelope or a single provider data row.
     * The requested transaction ID is the authoritative identity when the
     * status endpoint omits trx_id from its response row.
     *
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, ?string $requestedTrxId = null): self
    {
        $data = SakurupiahPayloadReader::firstDataItem($payload);
        $responseTrxId = SakurupiahPayloadReader::optionalString($data, 'trx_id', 255);
        $trxId = $requestedTrxId ?? $responseTrxId;
        if ($trxId === null || trim($trxId) !== $trxId || $trxId === '') {
            throw new SakurupiahSchemaException;
        }
        if ($responseTrxId !== null && $responseTrxId !== $trxId) {
            throw new SakurupiahSchemaException;
        }

        $statusValue = array_key_exists('payment_status', $data) ? $data['payment_status'] : ($data['status'] ?? null);
        $status = SakurupiahTransactionStatus::fromProviderValue($statusValue);
        $occurredAt = SakurupiahPayloadReader::optionalTimestamp($data, 'date', 'time')
            ?? SakurupiahPayloadReader::optionalTimestamp($data, 'tanggal', 'waktu');

        return new self(
            trxId: $trxId,
            status: $status,
            merchantRef: SakurupiahPayloadReader::optionalString($data, 'merchant_ref', 255),
            paymentCode: SakurupiahPayloadReader::optionalString($data, 'payment_kode', 64),
            amount: self::optionalAmount($data, 'amount'),
            occurredAt: $occurredAt,
            expiresAt: SakurupiahPayloadReader::optionalExpiry($data, 'expired', $occurredAt),
            sanitizedPayload: $payload,
        );
    }

    public function transactionId(): string
    {
        return $this->trxId;
    }

    public function trxId(): string
    {
        return $this->trxId;
    }

    /** @return array<mixed> */
    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    public function statusValue(): string
    {
        return $this->status->value;
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

    private static function optionalAmount(array $data, string $key): ?SakurupiahAmount
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        try {
            return SakurupiahAmount::from($data[$key]);
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
