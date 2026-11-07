<?php

namespace App\Services\WijayaPay;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class WijayaPayInvoice
{
    public function __construct(
        public string $refId,
        public string $trxReference,
        public string $paymentMethod,
        public WijayaPayStatus $status,
        public int $totalBayar,
        public int $totalFee,
        public int $totalDiterima,
        public string $feePayer,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $updatedAt,
        private ?string $qrString,
        private ?string $qrImageUrl,
        public array $sanitizedPayload,
    ) {
        if ($refId === '' || $trxReference === '' || $paymentMethod === '') {
            throw new InvalidArgumentException('WijayaPay invoice identity is invalid.');
        }

        if ($totalBayar < 1 || $totalFee < 0 || $totalDiterima < 0) {
            throw new InvalidArgumentException('WijayaPay invoice amount is invalid.');
        }

        if (! in_array($feePayer, ['customer', 'store'], true)) {
            throw new InvalidArgumentException('WijayaPay invoice fee payer is invalid.');
        }
    }

    /**
     * Parses the create response and the documented duplicate-ref response.
     * A duplicate ref_id returns the existing transaction status instead of
     * creating a second invoice, so both shapes are accepted here.
     *
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, WijayaPayCreateInvoice $request): self
    {
        $data = WijayaPayPayloadReader::dataObject($payload);

        $refId = WijayaPayPayloadReader::requiredString($data, 'ref_id', 255);
        if ($refId !== $request->refId) {
            throw new WijayaPaySchemaException('createInvoice');
        }

        $trxReference = WijayaPayPayloadReader::requiredString($data, 'trx_reference', 255);
        $paymentMethod = self::paymentMethod($data);
        if (strcasecmp($paymentMethod, WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS) !== 0) {
            throw new WijayaPaySchemaException('createInvoice');
        }

        $totalBayar = WijayaPayPayloadReader::requiredInteger($data, 'total_bayar');
        $totalFee = WijayaPayPayloadReader::optionalInteger($data, 'total_fee', allowZero: true) ?? 0;
        if ($totalFee > $totalBayar) {
            throw new WijayaPaySchemaException('createInvoice');
        }
        $totalDiterima = WijayaPayPayloadReader::optionalInteger($data, 'total_diterima', allowZero: true)
            ?? max(0, $totalBayar - $totalFee);

        if ($totalDiterima !== max(0, $totalBayar - $totalFee)) {
            throw new WijayaPaySchemaException('createInvoice');
        }

        $feePayer = self::feePayer($request->amount, $totalBayar, $totalFee);
        self::assertFeeWithinCap($totalFee, $request->amount);

        [$qrString, $qrImageUrl] = self::presentationValues($data);
        $status = self::status($payload, $data, default: WijayaPayStatus::Pending);

        return new self(
            refId: $refId,
            trxReference: $trxReference,
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            status: $status,
            totalBayar: $totalBayar,
            totalFee: $totalFee,
            totalDiterima: $totalDiterima,
            feePayer: $feePayer,
            expiresAt: WijayaPayPayloadReader::optionalTimestamp($data, 'expired'),
            updatedAt: WijayaPayPayloadReader::optionalTimestamp($data, 'updated_at'),
            qrString: $qrString,
            qrImageUrl: $qrImageUrl,
            sanitizedPayload: WijayaPayPayloadSanitizer::sanitize($payload),
        );
    }

    public function totalValue(): int
    {
        return $this->totalBayar;
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

    public function qrPayload(): ?string
    {
        return $this->qrString;
    }

    public function qrImageUrl(): ?string
    {
        return $this->qrImageUrl;
    }

    public function customerPaysFee(): bool
    {
        return $this->feePayer === 'customer';
    }

    /** @param array<mixed> $data */
    public static function paymentMethod(array $data): string
    {
        $documented = WijayaPayPayloadReader::optionalString($data, 'payment_method', 64);
        $legacy = WijayaPayPayloadReader::optionalString($data, 'payment_methode', 64);
        if ($documented !== null && $legacy !== null && strcasecmp($documented, $legacy) !== 0) {
            throw new WijayaPaySchemaException;
        }

        return $documented ?? $legacy ?? throw new WijayaPaySchemaException;
    }

    /**
     * The payable total must be exactly the local amount (store bears the
     * fee) or the local amount plus the provider fee (customer bears it).
     * Any other total is not attributable to this Payment.
     */
    public static function feePayer(int $amount, int $totalBayar, int $totalFee): string
    {
        if ($totalBayar === $amount) {
            return 'store';
        }

        if ($totalFee > 0 && $totalBayar === $amount + $totalFee) {
            return 'customer';
        }

        throw new WijayaPaySchemaException('amount');
    }

    public static function assertFeeWithinCap(int $totalFee, int $amount): void
    {
        $bps = config('services.wijayapay.qris_contract.max_fee_bps', 500);
        if (! is_numeric($bps) || (int) $bps < 0 || (int) $bps > 10_000) {
            throw new WijayaPaySchemaException('fee');
        }

        $maximum = intdiv($amount * (int) $bps + 9_999, 10_000);
        if ($totalFee > $maximum) {
            throw new WijayaPaySchemaException('fee');
        }
    }

    /** @param array<mixed> $payload @param array<mixed> $data */
    public static function status(array $payload, array $data, ?WijayaPayStatus $default = null): WijayaPayStatus
    {
        $value = $payload['status_pembayaran'] ?? $data['status_pembayaran'] ?? null;
        if ($value === null) {
            $candidate = $payload['status'] ?? $data['status'] ?? null;
            if (is_string($candidate) && in_array(strtolower(trim($candidate)), ['pending', 'paid', 'expired'], true)) {
                $value = $candidate;
            }
        }

        return $value === null
            ? ($default ?? throw new WijayaPaySchemaException('status'))
            : WijayaPayStatus::fromProviderValue($value);
    }

    /**
     * @param  array<mixed>  $data
     * @return array{0: ?string, 1: ?string}
     */
    private static function presentationValues(array $data): array
    {
        $qrString = WijayaPayPayloadReader::optionalString($data, 'qr_string', 1_000_000);
        $qrImageUrl = WijayaPayPayloadReader::optionalString($data, 'qr_image', 2048);

        if ($qrImageUrl !== null && ! WijayaPayProviderUrl::isAllowed($qrImageUrl)) {
            throw new WijayaPaySchemaException('presentation');
        }

        return [$qrString, $qrImageUrl];
    }
}
