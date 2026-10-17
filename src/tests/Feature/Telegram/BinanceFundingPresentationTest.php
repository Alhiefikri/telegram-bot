<?php

namespace Tests\Feature\Telegram;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\Callbacks\TelegramUsdtFundingCallbackHandler;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\Telegram\UsdtTopUpOutbound;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtTopUps\BinanceUsdtTopUpSettlement;
use App\Services\UsdtTopUps\UsdtTopUpStatusReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BinanceFundingPresentationTest extends TestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 08:00:00 UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'services.binance.api_key' => 'test-key', 'services.binance.api_secret' => 'test-secret',
            'services.binance.pay_id' => '12345678', 'services.binance.pay_uid' => '',
            'services.binance.history_cache_seconds' => 0, 'services.telegram.token' => 'test-token',
        ]);
        ManualPaymentSetting::create([
            'is_active' => true, 'payment_mode' => 'manual', 'binance_pay_enabled' => true,
            'binance_payment_ttl_minutes' => 15,
        ]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true]);
        $this->customer = Customer::factory()->create(['telegram_id' => 771050, 'locale' => 'en']);
        TelegramNavigationRoot::query()->create([
            'customer_id' => $this->customer->id, 'chat_id' => '771050', 'message_id' => 100,
            'media_kind' => 'text', 'screen' => 'home', 'locale' => 'en', 'generation' => 1,
        ]);
    }

    public function test_preview_keeps_snapshotted_receiver_exact_amount_and_both_time_limits(): void
    {
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 500_000]);
        $intent = $this->preview('0.300001');
        config(['services.binance.pay_id' => '87654321']);

        $surface = app(TelegramUsdtBalanceIntents::class)->previewSurface($this->customer, $intent);
        $copy = $this->visible($surface->caption);
        $this->assertStringContainsString('Nominal: 0.300001 USDT', $copy);
        $this->assertStringContainsString('Expected credit: 0.300001 USD (1 USDT = 1 USD)', $copy);
        $this->assertStringContainsString('Application fee: 0 USDT', $copy);
        $this->assertStringContainsString('Total to send: 0.300001 USDT', $copy);
        $this->assertStringContainsString('Binance Pay ID: 12345678', $copy);
        $this->assertStringContainsString('Confirm before: '.app(TelegramLocale::class)->formatDate($this->customer, $intent->expires_at), $copy);
        $this->assertStringContainsString('Payment window: 15 minutes after confirmation', $copy);
        $this->assertStringContainsString('<code>12345678</code>', $surface->caption);
        $this->assertStringContainsString('<code>0.300001</code>', $surface->caption);
        $this->assertStringNotContainsString('87654321', $copy);
        $this->assertSame('HTML', $surface->options['parse_mode']);
        $this->assertSame(0, CustomerUsdtTopUp::count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        Http::assertNothingSent();
    }

    public function test_confirmed_order_id_and_check_presentations_keep_exact_target_after_receiver_rotation(): void
    {
        $target = $this->confirm($this->preview('0.3'));
        config(['services.binance.pay_id' => '87654321']);
        app(BinanceUsdtTopUpSettlement::class)->submit($this->customer, $target->id, '445011487581044736');

        foreach (['balance.usdt.notice.order_prompt', 'balance.usdt.notice.order_recorded', 'balance.usdt.notice.refreshed'] as $notice) {
            $surface = app(TelegramUsdtBalanceIntents::class)->statusSurface(
                $this->customer, $target, app(UsdtTopUpStatusReader::class)->for($target), $notice,
            );
            $copy = $this->visible($surface->caption);
            $this->assertStringContainsString('Submitted amount: 0.3 USDT', $copy);
            $this->assertStringContainsString('Expected credit: 0.3 USD (1 USDT = 1 USD)', $copy);
            $this->assertStringContainsString('Application fee: 0 USDT', $copy);
            $this->assertStringContainsString('Total to send: 0.3 USDT', $copy);
            $this->assertStringContainsString('Binance Pay ID: 12345678', $copy);
            $this->assertStringContainsString('Payment deadline: '.app(TelegramLocale::class)->formatDate($this->customer, $target->expires_at), $copy);
            $this->assertStringContainsString($target->reference, $copy);
            $this->assertStringContainsString('<code>12345678</code>', $surface->caption);
            $this->assertStringContainsString('<code>0.3</code>', $surface->caption);
            $this->assertStringNotContainsString('87654321', $copy);
            $buttons = array_merge(...$surface->keyboard['inline_keyboard']);
            $this->assertContains('usdt:check:'.$target->id, array_column($buttons, 'callback_data'));
            $this->assertContains('usdt:order:'.$target->id, array_column($buttons, 'callback_data'));
        }
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        Http::assertNothingSent();
    }

    public function test_comma_and_dot_amounts_reach_one_identical_preview(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 08:00:00 UTC'));
        $actions = app(TelegramUsdtFundingActions::class);
        $dot = $actions->preview('771050', '771050', 'private', 100, '0.3', CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('opened', $dot->outcome);

        $comma = $actions->preview('771050', '771050', 'private', 100, '0,3', CustomerUsdtFundingRail::BinancePay);
        // Same canonical money reuses the same open target instead of creating a
        // second preview, and the zero-padded form resolves to it as well.
        $this->assertSame('replayed', $comma->outcome);
        $this->assertSame($dot->intent->id, $comma->intent->id);
        $this->assertSame($dot->intent->amount_micro, $comma->intent->amount_micro);
        $this->assertSame($dot->intent->payable_micro, $comma->intent->payable_micro);
        $this->assertSame('pending', $comma->intent->fresh()->state);
        $this->assertSame(
            $dot->intent->id,
            $actions->preview('771050', '771050', 'private', 100, '0.300000', CustomerUsdtFundingRail::BinancePay)->intent->id,
        );
        $this->assertSame(1, CustomerUsdtFundingIntent::where('state', 'pending')->count());
        $this->assertSame(0, CustomerUsdtTopUp::count());

        foreach (['0,3,4', '1,000,000', '1,2.3', '1.2,3', '0,1234567', '-0,3', '1e2'] as $invalid) {
            $rejected = $actions->preview('771050', '771050', 'private', 100, $invalid, CustomerUsdtFundingRail::BinancePay);
            $this->assertSame('invalid_amount', $rejected->outcome, $invalid);
        }
        $this->assertSame(1, CustomerUsdtFundingIntent::count());
        $this->assertSame(0, CustomerUsdtTopUp::count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
    }

    public function test_check_renders_durable_status_when_the_provider_fails(): void
    {
        $target = $this->confirm($this->preview('0.3'));
        app(BinanceUsdtTopUpSettlement::class)->submit($this->customer, $target->id, '445011487581044736');
        config([
            'services.telegram.api_base_url' => 'https://api.telegram.org',
            'services.telegram.webhook_secret' => 'secret',
            'services.binance.history_cache_seconds' => 0,
            'services.binance.history_max_attempts' => 1,
        ]);
        // The provider rejects the first lookup and then answers; a stub array
        // would accumulate (later fakes are appended), so the order lives in one
        // sequence.
        Http::fakeSequence('*sapi/v1/pay/transactions*')
            ->push(['code' => '100001001', 'success' => false, 'message' => 'Signature for this request is not valid.'], 400)
            ->push(['code' => '000000', 'success' => true, 'data' => [[
                'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
                'amount' => '0.3', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
                'orderType' => 'C2C', 'receiverInfo' => ['accountId' => '12345678'],
            ]]])
            ->push(['code' => '000000', 'success' => true, 'data' => [[
                'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
                'amount' => '0.3', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
                'orderType' => 'C2C', 'receiverInfo' => ['accountId' => '12345678'],
            ]]]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 902]])]);

        // The Check callback must not let a provider failure escape into the
        // webhook update: no exception leaves the handler.
        app(TelegramUsdtFundingCallbackHandler::class)->handle(new TelegramCallbackContext(
            customer: $this->customer,
            chatId: '771050',
            callbackQueryId: 'check-provider-failure',
            data: 'usdt:check:'.$target->id,
            chatType: 'private',
            messageId: 100,
        ));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sapi/v1/pay/transactions'));

        $edits = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
        $this->assertCount(1, $edits);
        $copy = $this->visible((string) $edits->sole()[0]['text']);
        $this->assertStringContainsString('Status refreshed from durable records', $copy);
        $this->assertStringContainsString('Status: Awaiting payment', $copy);
        $this->assertStringContainsString($target->reference, $copy);
        $this->assertStringNotContainsString('100001001', $copy);
        $this->assertStringNotContainsString('Signature for this request is not valid.', $copy);
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame(0, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));

        // The durable work stays owned by the automatic recovery path and no
        // money moved while the provider was failing.
        $this->assertSame('awaiting_payment', app(UsdtTopUpStatusReader::class)->for($target)->state);
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame(0, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));

        // Recovery resolves the same durably leased work once the provider
        // answers again, including after the failed check.
        $this->travel(3)->minutes();
        $this->assertTrue(app(BinanceUsdtTopUpSettlement::class)->check($target->id));
        $this->assertSame('settled', app(UsdtTopUpStatusReader::class)->for($target)->state);
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_uid_only_receiver_remains_copyable_after_confirmation(): void
    {
        config(['services.binance.pay_id' => '', 'services.binance.pay_uid' => '11223344']);
        $intent = $this->preview('0.3');
        $preview = app(TelegramUsdtBalanceIntents::class)->previewSurface($this->customer, $intent);
        $target = $this->confirm($intent);
        config(['services.binance.pay_uid' => '99887766']);
        $status = app(TelegramUsdtBalanceIntents::class)->statusSurface(
            $this->customer, $target, app(UsdtTopUpStatusReader::class)->for($target),
        );
        foreach ([$preview, $status] as $surface) {
            $this->assertStringContainsString('Binance UID: 11223344', $this->visible($surface->caption));
            $this->assertStringContainsString('<code>11223344</code>', $surface->caption);
            $this->assertStringNotContainsString('99887766', $surface->caption);
        }
    }

    public function test_verified_receipt_and_status_keep_transaction_balances_after_spending_and_replay(): void
    {
        $target = $this->settledTarget();
        $effect = TelegramOutboundEffect::sole();
        $message = $effect->outcome_metadata['message'];
        $this->assertReceipt($target, $message);
        $entry = CustomerUsdtBalanceEntry::sole();
        app(CustomerUsdtBalanceLedger::class)->debitPurchase($this->customer,
            new UsdtPurchase($this->customer->id, UsdtMicroAmount::fromUnits(100_000), 'presentation-order', 'presentation-payment'),
            'presentation:spend');
        $this->assertSame(200_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));

        $status = app(UsdtTopUpStatusReader::class)->for($target);
        $surface = app(TelegramUsdtBalanceIntents::class)->statusSurface($this->customer, $target, $status);
        $this->assertReceipt($target, $this->visible($surface->caption));
        $this->assertSame(0, $status->balanceBeforeMicro);
        $this->assertSame(300_000, $status->balanceAfterMicro);
        $this->assertSame(300_000, $status->receivedMicro);
        $this->assertSame(300_000, $status->creditMicro);
        $this->assertStringNotContainsString('0.2 USD', $surface->caption);

        $this->customer->update(['locale' => 'id']);
        config(['services.binance.pay_id' => '87654321']);
        $replayed = DB::transaction(fn () => app(UsdtTopUpOutbound::class)->recordReceipt($target, $entry, false));
        $this->assertSame($effect->id, $replayed->id);
        $this->assertSame($message, $replayed->outcome_metadata['message']);
        $this->assertSame(1, TelegramOutboundEffect::count());
        $this->assertSame(2, CustomerUsdtBalanceEntry::count());

        app(UsdtTopUpOutbound::class)->deliver($effect);
        app(UsdtTopUpOutbound::class)->deliver($effect);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['text'] === $message);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertSame('sent', $effect->fresh()->state->value);
        $this->assertSame(200_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
    }

    public function test_definite_receipt_failure_retries_same_snapshot_without_repeating_credit(): void
    {
        $target = $this->settledTarget();
        $effect = TelegramOutboundEffect::sole();
        $message = $effect->outcome_metadata['message'];
        $bot = \Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('sendMessage')->once()->with('771050', $message, [], ['parse_mode' => null])
            ->andThrow(new \RuntimeException('Telegram rejected request'));
        $bot->shouldReceive('sendMessage')->once()->with('771050', $message, [], ['parse_mode' => null])
            ->andReturn(['ok' => true, 'result' => ['message_id' => 902]]);
        $this->app->instance(TelegramBotClient::class, $bot);
        $this->app->forgetInstance(UsdtTopUpOutbound::class);
        $outbound = app(UsdtTopUpOutbound::class);
        $outbound->deliver($effect);
        $this->assertSame('failed', $effect->fresh()->state->value);
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->travel(1)->hours();
        $this->assertSame(1, $outbound->recover());
        $outbound->deliver($effect);
        $this->assertSame('sent', $effect->fresh()->state->value);
        $this->assertSame($message, $effect->fresh()->outcome_metadata['message']);
        $this->assertReceipt($target, $message);
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(1, TelegramOutboundEffect::count());
    }

    public function test_ambiguous_receipt_failure_preserves_credit_and_is_not_replayed_automatically(): void
    {
        $target = $this->settledTarget();
        $effect = TelegramOutboundEffect::sole();
        $message = $effect->outcome_metadata['message'];
        $bot = \Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('sendMessage')->once()->with('771050', $message, [], ['parse_mode' => null])
            ->andThrow(new ConnectionException('connection interrupted'));
        $this->app->instance(TelegramBotClient::class, $bot);
        $this->app->forgetInstance(UsdtTopUpOutbound::class);
        $outbound = app(UsdtTopUpOutbound::class);
        $outbound->deliver($effect);
        $this->assertSame('unknown', $effect->fresh()->state->value);
        $this->travel(1)->hours();
        $this->assertSame(0, $outbound->recover());
        $outbound->deliver($effect);
        app(BinanceUsdtTopUpSettlement::class)->submit($this->customer, $target->id, '445011487581044736');
        $this->assertSame($message, $effect->fresh()->outcome_metadata['message']);
        $this->assertReceipt($target, $message);
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
    }

    private function preview(string $amount): CustomerUsdtFundingIntent
    {
        $result = app(TelegramUsdtFundingActions::class)->preview('771050', '771050', 'private', 100, $amount, CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('opened', $result->outcome);

        return $result->intent;
    }

    private function confirm(CustomerUsdtFundingIntent $intent): CustomerUsdtTopUp
    {
        $result = app(TelegramUsdtFundingActions::class)->callback('771050', '771050', 'private', 100, 'usdt:confirm:'.$intent->nonce);
        $this->assertNotNull($result->topUp);

        return $result->topUp;
    }

    private function settledTarget(): CustomerUsdtTopUp
    {
        $target = $this->confirm($this->preview('0.30'));
        Http::fake([
            '*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => [[
                'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
                'amount' => '0.30', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
                'orderType' => 'C2C', 'receiverInfo' => ['accountId' => '12345678'],
            ]]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]]),
        ]);
        $settlement = app(BinanceUsdtTopUpSettlement::class);
        $settlement->submit($this->customer, $target->id, '445011487581044736');
        $settlement->check($target->id);
        $this->assertSame('settled', app(UsdtTopUpStatusReader::class)->for($target)->state);

        return $target;
    }

    private function assertReceipt(CustomerUsdtTopUp $target, string $copy): void
    {
        $this->assertStringContainsString('Submitted amount: 0.3 USDT', $copy);
        $this->assertStringContainsString('Verified received: 0.3 USDT', $copy);
        $this->assertStringContainsString('Credited: 0.3 USD', $copy);
        $this->assertStringContainsString('Balance before credit: 0 USD', $copy);
        $this->assertStringContainsString('Balance after credit: 0.3 USD', $copy);
        $this->assertStringContainsString($target->reference, $copy);
        $this->assertStringNotContainsString('TX-445011487581044736', $copy);
    }

    private function visible(string $caption): string
    {
        return html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
