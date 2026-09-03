<?php

namespace App\Services\Sakurupiah;

/**
 * Safe boundary returned by invoice creation and recovery.
 *
 * Non-payable states intentionally carry no provider presentation data.
 */
final readonly class SakurupiahInvoiceLifecycleResult
{
    private function __construct(
        private SakurupiahInvoiceOutcome $outcome,
        private ?SakurupiahPayableInvoice $payable,
    ) {}

    public static function payableResult(SakurupiahPayableInvoice $payable): self
    {
        return new self(SakurupiahInvoiceOutcome::Payable, $payable);
    }

    public static function state(SakurupiahInvoiceOutcome $outcome): self
    {
        if ($outcome === SakurupiahInvoiceOutcome::Payable) {
            throw new \InvalidArgumentException('A payable Sakurupiah outcome requires a payable payload.');
        }

        return new self($outcome, null);
    }

    public function outcome(): SakurupiahInvoiceOutcome
    {
        return $this->outcome;
    }

    public function payable(): ?SakurupiahPayableInvoice
    {
        return $this->payable;
    }

    public function isPayable(): bool
    {
        return $this->outcome === SakurupiahInvoiceOutcome::Payable;
    }
}
