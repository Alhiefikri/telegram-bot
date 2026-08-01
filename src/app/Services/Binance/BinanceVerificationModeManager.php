<?php

namespace App\Services\Binance;

use App\Models\ManualPaymentSetting;
use InvalidArgumentException;
use RuntimeException;

final readonly class BinanceVerificationModeManager
{
    public function __construct(
        private BinanceOrderIdActivation $activation,
        private BinanceOrderIdCanaryEvidence $evidence,
    ) {}

    public function change(ManualPaymentSetting $setting, string $mode): ManualPaymentSetting
    {
        if (! in_array($mode, ['amount_unique', 'order_id'], true)) {
            throw new InvalidArgumentException('Mode verifikasi Binance tidak valid.');
        }

        if ($mode === 'amount_unique') {
            $setting->forceFill([
                'binance_verification_mode' => 'amount_unique',
                'binance_order_id_compatibility_ready_at' => null,
            ])->save();

            return $setting->fresh();
        }

        if (! filled(config('services.binance.api_key')) || ! filled(config('services.binance.api_secret'))) {
            throw new RuntimeException('Binance API key dan secret harus dikonfigurasi sebelum Order ID diaktifkan.');
        }
        if (! filled(config('services.binance.pay_id')) && ! filled(config('services.binance.pay_uid'))) {
            throw new RuntimeException('Binance Pay ID atau UID harus dikonfigurasi sebelum Order ID diaktifkan.');
        }

        $this->evidence->assertSuccessfulRecentAdminCanary();
        $setting->forceFill(['binance_order_id_compatibility_ready_at' => now()])->save();
        $this->activation->activate();

        return $setting->fresh();
    }
}
