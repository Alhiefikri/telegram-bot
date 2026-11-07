<?php

namespace App\Services\WijayaPay;

interface WijayaPayProviderAdapter
{
    /** @return list<WijayaPayChannel> */
    public function listChannels(): array;

    public function createInvoice(WijayaPayCreateInvoice $invoice): WijayaPayInvoice;

    public function transactionStatus(string $refId): WijayaPayStatusResponse;
}
