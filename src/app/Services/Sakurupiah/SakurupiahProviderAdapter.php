<?php

namespace App\Services\Sakurupiah;

interface SakurupiahProviderAdapter
{
    /** @return list<SakurupiahChannel> */
    public function listChannels(): array;

    public function checkBalance(): SakurupiahBalance;

    public function createInvoice(SakurupiahCreateInvoice $invoice): SakurupiahInvoice;

    public function transactionStatus(string $trxId): SakurupiahStatus;

    /** @return list<SakurupiahHistoryItem> */
    public function transactionHistory(?SakurupiahHistoryFilter $filter = null): array;
}
