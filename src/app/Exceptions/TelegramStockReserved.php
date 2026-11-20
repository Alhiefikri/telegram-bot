<?php

namespace App\Exceptions;

final class TelegramStockReserved extends InvalidCheckout
{
    public function __construct()
    {
        parent::__construct('Stok terakhir disisakan untuk pembelian lewat Telegram. Pilih jumlah lebih kecil atau coba lagi nanti.');
    }
}
