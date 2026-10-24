<?php

namespace App\Services\Telegram;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class TelegramQrCodeRenderer
{
    private const OUTPUT_TYPE_PNG = 'png';

    public function png(string $content, int $scale = 8): string
    {
        $options = new QROptions([
            'outputType' => self::OUTPUT_TYPE_PNG,
            'outputBase64' => false,
            'scale' => max(1, $scale),
        ]);

        return (new QRCode($options))->render($content);
    }
}
