<?php

namespace App\Exceptions;

final class TelegramStarsPaymentVerifying extends InvalidOrderTransition
{
    public function __construct()
    {
        parent::__construct('Pembayaran Telegram Stars sedang diverifikasi.');
    }
}
