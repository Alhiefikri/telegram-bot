<?php

namespace App\Services\Pakasir;

use App\Models\Payment;

interface PakasirProviderAdapter
{
    public function hostedPaymentUrl(Payment $payment): string;

    public function createQrisTransaction(Payment $payment): PakasirQrisTransaction;

    public function transactionDetail(Payment $payment): array;
}
