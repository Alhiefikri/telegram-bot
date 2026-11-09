<?php

namespace App\Storefront;

use App\Exceptions\InvalidCheckout;

final class StorefrontCheckoutLimitReached extends InvalidCheckout
{
    /** @param list<string> $invoiceUrls */
    public function __construct(public readonly array $invoiceUrls, bool $sharedNetwork = false)
    {
        parent::__construct($sharedNetwork
            ? 'Jaringan ini memiliki terlalu banyak pembelian yang menunggu pembayaran. Selesaikan atau batalkan invoice Anda, atau coba lagi nanti. Pembelian ini belum dibuat.'
            : 'Anda masih memiliki 2 pembelian yang menunggu pembayaran. Selesaikan atau batalkan invoice sebelumnya sebelum membuat pembelian lain. Pembelian ini belum dibuat.');
    }
}
