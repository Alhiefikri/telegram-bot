<?php

namespace App\Services\WijayaPay;

use Carbon\CarbonImmutable;

/**
 * Authoritative status observation. Identity is keyed by the merchant
 * reference (`ref_id`) because that is the documented lookup key.
 */
final readonly class WijayaPayStatusResponse
{
    public function __construct(
        public string $refId,
        public string $trxReference,
        public ?string $paymentMethod,
        public WijayaPayStatus $status,
        public ?int $totalBayar,
        public ?int $totalDibayar,
        public ?int $totalFee,
        public ?int $amountReceived,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $updatedAt,
        public array $sanitizedPayload,
        private ?string $qrString = null,
        private ?string $qrImageUrl = null,
    ) {
        if ($refId === '' || $trxReference === '') {
            throw new WijayaPaySchemaException('transactionStatus');
        }
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, string $requestedRefId): self
    {
        $data = WijayaPayPayloadReader::dataObject($payload);
        $refId = WijayaPayPayloadReader::optionalString($data, 'ref_id', 255) ?? $requestedRefId;
        if ($refId !== $requestedRefId) {
            throw new WijayaPaySchemaException('transactionStatus');
        }

        $paymentMethod = WijayaPayPayloadReader::optionalString($data, 'payment_method', 64)
            ?? WijayaPayPayloadReader::optionalString($data, 'payment_methode', 64);
        if ($paymentMethod !== null
            && strcasecmp($paymentMethod, WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS) !== 0
        ) {
            throw new WijayaPaySchemaException('transactionStatus');
        }

        $totalBayar = WijayaPayPayloadReader::optionalInteger($data, 'total_bayar', allowZero: true);
        $totalDibayar = WijayaPayPayloadReader::optionalInteger($data, 'total_dibayar', allowZero: true);
        $totalFee = WijayaPayPayloadReader::optionalInteger($data, 'total_fee', allowZero: true);
        $amountReceived = WijayaPayPayloadReader::optionalInteger($data, 'amount_received', allowZero: true);

        if ($totalBayar !== null && $totalDibayar !== null && $totalBayar !== $totalDibayar) {
            throw new WijayaPaySchemaException('transactionStatus');
        }

        if ($totalBayar !== null && $totalFee !== null && $totalFee > $totalBayar) {
            throw new WijayaPaySchemaException('transactionStatus');
        }

        if ($totalBayar !== null && $totalFee !== null && $amountReceived !== null) {
            if ($amountReceived !== max(0, $totalBayar - $totalFee)) {
                throw new WijayaPaySchemaException('transactionStatus');
            }
        }

        [$qrString, $qrImageUrl] = WijayaPayInvoice::presentationValues($data, $totalBayar ?? $totalDibayar);

        return new self(
            refId: $refId,
            trxReference: WijayaPayPayloadReader::requiredString($data, 'trx_reference', 255),
            paymentMethod: $paymentMethod,
            status: WijayaPayInvoice::status($payload, $data),
            totalBayar: $totalBayar,
            totalDibayar: $totalDibayar,
            totalFee: $totalFee,
            amountReceived: $amountReceived,
            expiresAt: WijayaPayPayloadReader::optionalTimestamp($data, 'expired'),
            updatedAt: WijayaPayPayloadReader::optionalTimestamp($data, 'updated_at'),
            sanitizedPayload: WijayaPayPayloadSanitizer::sanitize($payload),
            qrString: $qrString,
            qrImageUrl: $qrImageUrl,
        );
    }

    public function qrPayload(): ?string
    {
        return $this->qrString;
    }

    public function qrImageUrl(): ?string
    {
        return $this->qrImageUrl;
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

    /**
     * The paid amount evidence. The status endpoint documents `total_bayar`,
     * while a paid callback reports `total_dibayar`; both are authoritative
     * when returned by the status lookup.
     */
    public function amountValue(): ?int
    {
        return $this->totalBayar ?? $this->totalDibayar;
    }

    public function totalValue(): ?int
    {
        return $this->totalBayar;
    }

    public function feeValue(): ?int
    {
        return $this->totalFee;
    }
}
