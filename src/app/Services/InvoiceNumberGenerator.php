<?php

namespace App\Services;

use Illuminate\Support\Str;

class InvoiceNumberGenerator
{
    public function invoiceNumber(): string
    {
        return 'INV-'.now()->format('Ymd').'-'.(string) Str::ulid();
    }

    public function merchantReference(): string
    {
        return 'PAY-'.now()->format('Ymd').'-'.(string) Str::ulid();
    }
}
