<?php

namespace App\Console\Commands;

use App\Models\ManualPaymentSetting;
use App\Services\Binance\BinanceVerificationModeManager;
use Illuminate\Console\Command;

class MarkBinanceOrderIdReady extends Command
{
    protected $signature = 'binance:mark-order-id-ready {--confirm-canary : Confirm that a real buyer-visible Order ID canary passed}';

    protected $description = 'Record the Binance Order ID compatibility readiness marker after the live canary passes.';

    public function handle(BinanceVerificationModeManager $modes): int
    {
        if (! $this->option('confirm-canary')) {
            $this->error('Refusing to mark readiness without --confirm-canary.');

            return self::INVALID;
        }

        $setting = ManualPaymentSetting::query()->where('is_active', true)->oldest('id')->first();
        if (! $setting) {
            $this->error('No active manual payment settings row exists.');

            return self::FAILURE;
        }

        try {
            $setting = $modes->change($setting, 'order_id');
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Binance Order ID compatibility marked ready and activated at '.$setting->binance_order_id_compatibility_ready_at->toIso8601String().'.');

        return self::SUCCESS;
    }
}
