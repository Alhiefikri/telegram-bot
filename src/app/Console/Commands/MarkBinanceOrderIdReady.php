<?php

namespace App\Console\Commands;

use App\Models\ManualPaymentSetting;
use App\Services\Binance\BinanceOrderIdActivation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkBinanceOrderIdReady extends Command
{
    protected $signature = 'binance:mark-order-id-ready {--confirm-canary : Confirm that a real buyer-visible Order ID canary passed}';

    protected $description = 'Record the Binance Order ID compatibility readiness marker after the live canary passes.';

    public function handle(BinanceOrderIdActivation $activation): int
    {
        if (! $this->option('confirm-canary')) {
            $this->error('Refusing to mark readiness without --confirm-canary.');

            return self::INVALID;
        }

        if (! filled(config('services.binance.api_key')) || ! filled(config('services.binance.api_secret'))) {
            $this->error('Binance API credentials are not configured.');

            return self::FAILURE;
        }

        if (! filled(config('services.binance.pay_id')) && ! filled(config('services.binance.pay_uid'))) {
            $this->error('BINANCE_PAY_ID or BINANCE_PAY_UID must be configured.');

            return self::FAILURE;
        }

        $setting = ManualPaymentSetting::query()->where('is_active', true)->oldest('id')->first();
        if (! $setting) {
            $this->error('No active manual payment settings row exists.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($setting, $activation): void {
                $setting->update(['binance_order_id_compatibility_ready_at' => now()]);
                $activation->activate();
            }, 5);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Binance Order ID activation was not applied safely. Resolve the readiness checks and try again.');

            return self::FAILURE;
        }

        $this->info('Binance Order ID compatibility marked ready and activated at '.$setting->fresh()->binance_order_id_compatibility_ready_at->toIso8601String().'.');

        return self::SUCCESS;
    }
}
