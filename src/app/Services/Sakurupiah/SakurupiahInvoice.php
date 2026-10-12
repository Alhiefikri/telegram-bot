<?php

namespace App\Services\Sakurupiah;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SakurupiahInvoice
{
    public string $trxId;

    public string $merchantRef;

    public string $paymentCode;

    public SakurupiahTransactionStatus $status;

    public SakurupiahAmount $total;

    public ?SakurupiahAmount $amount;

    public ?SakurupiahFee $fee;

    public ?int $merchantFee;

    public ?CarbonImmutable $createdAt;

    public ?CarbonImmutable $expiresAt;

    public bool $hasQrPayload;

    private ?string $qrPayload;

    private ?string $checkoutUrl;

    private bool $tokenizedCheckoutUrl;

    /** @var array<mixed> */
    public array $sanitizedPayload;

    public function __construct(
        string $trxId,
        string $merchantRef,
        string $paymentCode,
        SakurupiahTransactionStatus $status,
        SakurupiahAmount|int|string $total,
        SakurupiahAmount|int|string|null $amount = null,
        ?SakurupiahFee $fee = null,
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $expiresAt = null,
        ?string $qr = null,
        ?string $checkoutUrl = null,
        array $sanitizedPayload = [],
        ?int $merchantFee = null,
    ) {
        $this->trxId = self::text($trxId, 'transaction ID', 255);
        $this->merchantRef = self::text($merchantRef, 'merchant reference', 255);
        $this->paymentCode = self::text($paymentCode, 'payment code', 64);
        $this->status = $status;
        $this->total = self::amount($total, allowZero: false);
        $this->amount = $amount === null ? null : self::amount($amount, allowZero: false);
        $this->fee = $fee;
        $this->merchantFee = $merchantFee;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->hasQrPayload = $qr !== null;
        $this->qrPayload = $qr;
        $this->checkoutUrl = $checkoutUrl;
        $this->tokenizedCheckoutUrl = self::hasQueryOrFragment($checkoutUrl);
        $this->sanitizedPayload = SakurupiahPayloadSanitizer::sanitize($sanitizedPayload);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, ?SakurupiahCreateInvoice $request = null): self
    {
        $data = SakurupiahPayloadReader::firstDataItem($payload);
        $trxId = SakurupiahPayloadReader::requiredString($data, 'trx_id', 255);
        $merchantRef = SakurupiahPayloadReader::requiredString($data, 'merchant_ref', 255);
        if ($request !== null && $merchantRef !== $request->merchantRef) {
            throw new SakurupiahSchemaException;
        }

        $paymentCode = SakurupiahPayloadReader::requiredString($data, 'payment_kode', 64);
        if ($request !== null && $paymentCode !== $request->method) {
            throw new SakurupiahSchemaException;
        }

        $statusValue = array_key_exists('payment_status', $data) ? $data['payment_status'] : ($data['status'] ?? null);
        $status = SakurupiahTransactionStatus::fromProviderValue($statusValue);
        $total = self::requiredAmount($data, 'total');
        $amount = self::optionalAmount($data, 'amount');
        if ($request !== null && $amount !== null && $amount->value() !== $request->amount->value()) {
            throw new SakurupiahSchemaException;
        }

        $createdAt = SakurupiahPayloadReader::optionalTimestamp($data, 'date', 'time')
            ?? SakurupiahPayloadReader::optionalTimestamp($data, 'tanggal', 'waktu');
        $expiresAt = SakurupiahPayloadReader::optionalExpiry($data, 'expired', $createdAt);
        $fee = self::optionalFee($data, 'fee');
        $merchantFee = self::optionalMerchantFee($data);

        return new self(
            trxId: $trxId,
            merchantRef: $merchantRef,
            paymentCode: $paymentCode,
            status: $status,
            total: $total,
            amount: $amount,
            fee: $fee,
            createdAt: $createdAt,
            expiresAt: $expiresAt,
            qr: SakurupiahPayloadReader::optionalString($data, 'qr', 1_000_000),
            checkoutUrl: self::optionalCheckoutUrl($data),
            sanitizedPayload: $payload,
            merchantFee: $merchantFee,
        );
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

    public function statusValue(): string
    {
        return $this->status->value;
    }

    public function totalValue(): int
    {
        return $this->total->value();
    }

    public function amountValue(): ?int
    {
        return $this->amount?->value();
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

    public function hasQrPayload(): bool
    {
        return $this->hasQrPayload;
    }

    public function qrPayload(): ?string
    {
        return $this->qrPayload;
    }

    public function checkoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function merchantFee(): ?int
    {
        return $this->merchantFee;
    }

    public function hasTokenizedCheckoutUrl(): bool
    {
        return $this->tokenizedCheckoutUrl;
    }

    private static function requiredAmount(array $data, string $key): SakurupiahAmount
    {
        if (! array_key_exists($key, $data)) {
            throw new SakurupiahSchemaException;
        }

        return self::amount($data[$key], allowZero: false);
    }

    /** @param array<mixed> $data */
    private static function optionalCheckoutUrl(array $data): ?string
    {
        $value = SakurupiahPayloadReader::optionalString($data, 'checkout_url', 2048);
        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)
            || array_key_exists('port', $parts)
        ) {
            throw new SakurupiahSchemaException;
        }

        return $value;
    }

    private static function hasQueryOrFragment(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts) && (array_key_exists('query', $parts) || array_key_exists('fragment', $parts));
    }

    private static function optionalAmount(array $data, string $key): ?SakurupiahAmount
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return self::amount($data[$key], allowZero: false);
    }

    private static function amount(mixed $value, bool $allowZero): SakurupiahAmount
    {
        try {
            return $value instanceof SakurupiahAmount
                ? $value
                : SakurupiahAmount::from($value, allowZero: $allowZero);
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }
    }

    private static function optionalFee(array $data, string $key): ?SakurupiahFee
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        try {
            return SakurupiahFee::from($data[$key]);
        } catch (InvalidArgumentException $exception) {
            throw new SakurupiahSchemaException(previous: $exception);
        }
    }

    private static function optionalMerchantFee(array $data): ?int
    {
        $value = SakurupiahPayloadReader::optionalString($data, 'merchant_fee', 64);
        if ($value === null) {
            return null;
        }

        // The provider echoes the payer as a word: `Merchant` when the store
        // bears the fee and `Pelanggan` when the buyer does.
        return match (strtolower($value)) {
            '1', 'merchant' => 1,
            '2', 'buyer', 'customer', 'pelanggan' => 2,
            default => throw new SakurupiahSchemaException,
        };
    }

    private static function text(string $value, string $label, int $maxLength): string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }

        return $value;
    }
}
