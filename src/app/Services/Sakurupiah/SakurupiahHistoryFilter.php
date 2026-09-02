<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final readonly class SakurupiahHistoryFilter
{
    public ?int $mechant;

    public ?string $paymentKode;

    public ?string $trxId;

    public ?string $merchantRef;

    public ?SakurupiahTransactionStatus $status;

    public ?string $tanggalAwal;

    public ?string $tanggalAkhir;

    public function __construct(
        ?int $mechant = 1,
        ?string $paymentKode = null,
        ?string $trxId = null,
        ?string $merchantRef = null,
        SakurupiahTransactionStatus|string|null $status = null,
        ?string $tanggalAwal = null,
        ?string $tanggalAkhir = null,
    ) {
        if ($mechant !== null && $mechant < 0) {
            throw new InvalidArgumentException('Sakurupiah merchant filter is invalid.');
        }

        $this->mechant = $mechant;
        $this->paymentKode = self::optionalText($paymentKode, 'payment code', 64);
        $this->trxId = self::optionalText($trxId, 'transaction ID', 255);
        $this->merchantRef = self::optionalText($merchantRef, 'merchant reference', 255);
        $this->status = self::status($status);
        $this->tanggalAwal = self::date($tanggalAwal, 'start date');
        $this->tanggalAkhir = self::date($tanggalAkhir, 'end date');
        if ($this->tanggalAwal !== null && $this->tanggalAkhir !== null && $this->tanggalAwal > $this->tanggalAkhir) {
            throw new InvalidArgumentException('Sakurupiah history date range is invalid.');
        }
    }

    public static function forTransaction(string $trxId): self
    {
        return new self(trxId: $trxId);
    }

    public static function forMerchantReference(string $merchantRef): self
    {
        return new self(merchantRef: $merchantRef);
    }

    /**
     * @return array<string, int|string>
     */
    public function toProviderPayload(): array
    {
        return array_filter([
            'mechant' => $this->mechant,
            'payment_kode' => $this->paymentKode,
            'trx_id' => $this->trxId,
            'merchant_ref' => $this->merchantRef,
            'status' => $this->status?->value,
            'tanggal_awal' => $this->tanggalAwal,
            'tanggal_akhir' => $this->tanggalAkhir,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function status(SakurupiahTransactionStatus|string|null $status): ?SakurupiahTransactionStatus
    {
        if ($status === null || $status instanceof SakurupiahTransactionStatus) {
            return $status;
        }

        return SakurupiahTransactionStatus::fromProviderValue($status);
    }

    private static function optionalText(?string $value, string $label, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (trim($value) !== $value || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }

        return $value;
    }

    private static function date(?string $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }

        try {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.', previous: $exception);
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }

        return $value;
    }
}
