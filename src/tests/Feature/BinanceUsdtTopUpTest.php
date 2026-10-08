<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtTopUps\BinanceUsdtTopUpSettlement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BinanceUsdtTopUpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.binance.api_key' => 'test-key', 'services.binance.api_secret' => 'test-secret',
            'services.binance.pay_id' => '12345678', 'services.binance.pay_uid' => '', 'services.binance.history_cache_seconds' => 0]);
        ManualPaymentSetting::create(['is_active' => true, 'payment_mode' => 'manual', 'binance_pay_enabled' => true]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true]);
    }

    public function test_verified_receipt_credits_once_and_cannot_be_reused_by_another_customer(): void
    {
        $target = $this->target(771001);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => [[
            'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
            'amount' => '1.5', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
            'orderType' => 'C2C', 'receiverInfo' => ['accountId' => '12345678'],
        ]]])]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $customer = Customer::query()->findOrFail($target->customer_id);
        $service->submit($customer, $target->id, '445011487581044736');
        Artisan::call('usdt-funding:recover');
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->assertSame(1500000, (int) DB::table('customer_usdt_balance_entries')->sum('amount_micro'));
        $service->submit($customer, $target->id, '445011487581044736');
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $other = $this->target(771002);
        $service->submit(Customer::query()->findOrFail($other->customer_id), $other->id, '445011487581044736');
        $service->check($other->id);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $other->id)->value('state'));
    }

    public function test_mismatched_amount_does_not_credit(): void
    {
        $target = $this->target(771003);
        Http::fake(['*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => [[
            'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
            'amount' => '2', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
            'receiverInfo' => ['accountId' => '12345678'],
        ]]])]);
        $service = app(BinanceUsdtTopUpSettlement::class);
        $service->submit(Customer::query()->findOrFail($target->customer_id), $target->id, '445011487581044736');
        $service->check($target->id);
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
    }

    private function target(int $telegramId): CustomerUsdtTopUp
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        TelegramNavigationRoot::query()->create(['customer_id' => $customer->id, 'chat_id' => (string) $telegramId,
            'message_id' => 100, 'media_kind' => 'text', 'screen' => 'home', 'locale' => 'id', 'generation' => 1]);
        $actions = app(TelegramUsdtFundingActions::class);
        $preview = $actions->preview((string) $telegramId, (string) $telegramId, 'private', 100, '1.5', CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('opened', $preview->outcome);

        return $actions->callback((string) $telegramId, (string) $telegramId, 'private', 100, 'usdt:confirm:'.$preview->intent->nonce)->topUp;
    }
}
