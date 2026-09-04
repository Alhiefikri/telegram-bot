<?php

namespace App\Services\Sakurupiah;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SakurupiahHistoryItem
{
    public string $trxId;

    public string $merchantRef;

    public string $paymentCode;

    public SakurupiahAmount $amount;

    public SakurupiahTransactionStatus $status;

    public ?string $currency;

    public ?CarbonImmutable $occurredAt;

    public ?CarbonImmutable $expiresAt;

    /** @var array<mixed> */
    public array $sanitizedPayload;

    public function __construct(
        string $trxId,
        string $merchantRef,
        string $paymentCode,
        SakurupiahAmount|int|string $amount,
        SakurupiahTransactionStatus $status,
        ?CarbonImmutable $occurredAt = null,
        ?CarbonImmutable $expiresAt = null,
        array $sanitizedPayload = [],
        ?string $currency = null,
    ) {
        $this->trxId = self::text($trxId, 'transaction ID', 255);
        $this->merchantRef = self::text($merchantRef, 'merchant reference', 255);
        $this->paymentCode = self::text($paymentCode, 'payment code', 64);
        $this->amount = $amount instanceof SakurupiahAmount ? $amount : SakurupiahAmount::from($amount);
        $this->status = $status;
        $this->currency = $currency === null ? null : self::text($currency, 'currency', 16);
        $this->occurredAt = $occurredAt;
        $this->expiresAt = $expiresAt;
        $this->sanitizedPayload = SakurupiahPayloadSanitizer::sanitize($sanitizedPayload);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $data = SakurupiahPayloadReader::firstDataItem($payload);
        $occurredAt = SakurupiahPayloadReader::optionalTimestamp($data, 'tanggal', 'waktu')
            ?? SakurupiahPayloadReader::optionalTimestamp($data, 'date', 'time');

        try {
            return new self(
                trxId: SakurupiahPayloadReader::requiredString($data, 'trx_id', 255),
                merchantRef: SakurupiahPayloadReader::requiredString($data, 'merchant_ref', 255),
                paymentCode: SakurupiahPayloadReader::requiredString($data, 'payment_kode', 64),
                amount: SakurupiahAmount::from(SakurupiahPayloadReader::requiredStringOrInteger($data, 'amount'), allowZero: false),
                status: SakurupiahTransactionStatus::fromProviderValue($data['status'] ?? null),
                occurredAt: $occurredAt,
                expiresAt: SakurupiahPayloadReader::optionalExpiry($data, 'expired', $occurredAt),
                currency: SakurupiahPayloadReader::optionalString($data, 'currency', 16),
                sanitizedPayload: $payload,
            );
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }
    }

    public function transactionId(): string
    {
        return $this->trxId;
    }

    /** @return array<mixed> */
    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    public function amountValue(): int
    {
        return $this->amount->value();
    }

    public function statusValue(): string
    {
        return $this->status->value;
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    private static function text(string $value, string $label, int $maxLength): string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }

        return $value;
    }
}
