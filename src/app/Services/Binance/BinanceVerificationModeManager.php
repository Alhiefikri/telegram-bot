<?php

namespace App\Services\Binance;

use App\Models\ManualPaymentSetting;
use InvalidArgumentException;
use RuntimeException;

final readonly class BinanceVerificationModeManager
{
    public function change(ManualPaymentSetting $setting, string $mode): ManualPaymentSetting
    {
        if (! in_array($mode, ['amount_unique', 'order_id'], true)) {
            throw new InvalidArgumentException('Mode verifikasi Binance tidak valid.');
        }

        if ($mode === 'order_id') {
            if (! filled(config('services.binance.api_key')) || ! filled(config('services.binance.api_secret'))) {
                throw new RuntimeException('Binance API key dan secret harus dikonfigurasi sebelum Order ID diaktifkan.');
            }
            if (! filled(config('services.binance.pay_id')) && ! filled(config('services.binance.pay_uid'))) {
                throw new RuntimeException('Binance Pay ID atau UID harus dikonfigurasi sebelum Order ID diaktifkan.');
            }
        }

        $setting->forceFill(['binance_verification_mode' => $mode])->save();

        return $setting->fresh();
    }
}
