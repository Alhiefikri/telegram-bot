<?php

namespace App\Services\KlikQris;

use App\Models\Payment;

interface KlikQrisProviderAdapter
{
    public function createTransaction(Payment $payment): KlikQrisTransaction;

    public function transactionStatus(Payment $payment): KlikQrisTransaction;
}
