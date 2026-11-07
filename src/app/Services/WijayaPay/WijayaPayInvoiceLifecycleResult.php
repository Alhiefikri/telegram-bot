<?php

namespace App\Services\WijayaPay;

final readonly class WijayaPayInvoiceLifecycleResult
{
    private function __construct(
        private WijayaPayInvoiceOutcome $outcome,
        private ?WijayaPayPayableInvoice $payable,
    ) {}

    public static function state(WijayaPayInvoiceOutcome $outcome): self
    {
        return new self($outcome, null);
    }

    public static function payableResult(WijayaPayPayableInvoice $payable): self
    {
        return new self(WijayaPayInvoiceOutcome::Payable, $payable);
    }

    public function outcome(): WijayaPayInvoiceOutcome
    {
        return $this->outcome;
    }

    public function payable(): ?WijayaPayPayableInvoice
    {
        return $this->payable;
    }

    public function isPayable(): bool
    {
        return $this->outcome === WijayaPayInvoiceOutcome::Payable && $this->payable !== null;
    }
}
