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
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramUsdtBalanceIntents;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\Telegram\TelegramUsdtFundingSession;
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
            'services.binance.history_cache_seconds' => 0, 'services.binance.history_max_attempts' => 1,
            'services.telegram.token' => 'test-token',
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

    public function test_confirmed_target_snapshots_receiver_amount_and_payable_instructions(): void
    {
        $target = $this->open('0.300001');
        config(['services.binance.pay_id' => '87654321']);

        $surface = $this->statusSurface($target, 'balance.usdt.notice.confirmed');
        $copy = $this->visible($surface->caption);
        $this->assertStringContainsString('🧾 Invoice: usdt:', $copy);
        $this->assertStringContainsString('Send exactly: 0.300001 USDT', $copy);
        $this->assertStringContainsString('Balance credit: 0.300001 USD', $copy);
        $this->assertStringNotContainsString('Application fee', $copy);
        $this->assertStringContainsString("Binance Pay ID\n12345678", $copy);
        $this->assertStringContainsString('until '.app(TelegramLocale::class)->formatDate($this->customer, $target->expires_at), $copy);
        $this->assertStringContainsString('Proof is required. Sending money is not enough.', $copy);
        $this->assertStringContainsString('Then paste your Binance Order ID in this chat.', $copy);
        $this->assertStringContainsString('Status: Awaiting payment', $copy);
        $this->assertStringContainsString($target->reference, $copy);
        $this->assertStringContainsString('<code>12345678</code>', $surface->caption);
        $this->assertStringContainsString('<code>0.300001</code>', $surface->caption);
        $this->assertStringNotContainsString('87654321', $copy);
        $this->assertSame('HTML', $surface->options['parse_mode']);
        $buttons = array_merge(...$surface->keyboard['inline_keyboard']);
        $this->assertContains('usdt:check:'.$target->id, array_column($buttons, 'callback_data'));
        $this->assertContains('usdt:order:'.$target->id, array_column($buttons, 'callback_data'));
        $this->assertSame(1, CustomerUsdtTopUp::count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        Http::assertNothingSent();
    }

    public function test_order_id_paste_renders_checking_placeholder_before_the_settled_status_with_one_credit(): void
    {
        $target = $this->open('0.3');
        app(TelegramUsdtFundingSession::class)->promptHash($this->customer, $target->id);
        $this->fakeTransactionHistory([$this->transaction($target)]);

        $this->assertTrue(app(TelegramUsdtFundingCallbackHandler::class)->handleOrderIdInput(
            $this->customer,
            '771050',
            '445011487581044736',
        ));

        $edits = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'))->values();
        $this->assertCount(2, $edits, 'The placeholder and the durable result both edit the canonical message.');
        $checking = $edits[0][0]->data();
        $result = $edits[1][0]->data();
        $this->assertSame((int) $checking['message_id'], (int) $result['message_id']);

        $checkingCopy = $this->visible((string) $checking['text']);
        $this->assertStringContainsString('⏳ Checking your payment…', $checkingCopy);
        $this->assertStringContainsString('Matching the Binance Order ID against the payment history. This takes a moment.', $checkingCopy);
        $this->assertStringNotContainsString('Balance after credit', $checkingCopy);
        $this->assertSame(['inline_keyboard' => []], $checking['reply_markup']);

        $resultCopy = $this->visible((string) $result['text']);
        $this->assertStringContainsString('Payment received and the balance was credited.', $resultCopy);
        $this->assertStringContainsString('Nominal: 0.3 USDT', $resultCopy);
        $this->assertStringContainsString('Verified received: 0.3 USDT', $resultCopy);
        $this->assertStringContainsString('Credited: 0.3 USD', $resultCopy);
        $this->assertStringContainsString('Balance before credit: 0 USD', $resultCopy);
        $this->assertStringContainsString('Balance after credit: 0.3 USD', $resultCopy);
        $this->assertStringContainsString('Status: Balance credited', $resultCopy);
        $this->assertStringContainsString($target->reference, $resultCopy);
        $resultMarkup = json_encode($result['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('usdt:check:', $resultMarkup);
        $this->assertStringNotContainsString('usdt:order:', $resultMarkup);
        $this->assertStringNotContainsString('usdt:history', $resultMarkup);
        $this->assertStringContainsString('menu:home', $resultMarkup);

        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->assertSame('settled', app(UsdtTopUpStatusReader::class)->for($target)->state);
        $this->assertSame(1, TelegramOutboundEffect::count());
        $this->assertSame('usdt.top_up_receipt', TelegramOutboundEffect::sole()->effect_type);
        $this->assertSame(1, CustomerUsdtTopUp::count());
    }

    public function test_order_id_paste_without_provider_evidence_records_a_hint_and_credits_nothing(): void
    {
        $target = $this->open('0.3');
        app(TelegramUsdtFundingSession::class)->promptHash($this->customer, $target->id);
        $this->fakeTransactionHistory([]);

        $this->assertTrue(app(TelegramUsdtFundingCallbackHandler::class)->handleOrderIdInput(
            $this->customer,
            '771050',
            '445011487581044736',
        ));

        $edits = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'))->values();
        $this->assertCount(2, $edits);
        $this->assertStringContainsString('⏳ Checking your payment…', $this->visible((string) $edits[0][0]['text']));
        $copy = $this->visible((string) $edits[1][0]['text']);
        $this->assertStringContainsString('Order ID received as a lookup hint.', $copy);
        $this->assertStringContainsString('Send exactly: 0.3 USDT', $copy);
        $this->assertStringContainsString('Status: Awaiting payment', $copy);

        $this->assertSame(1, DB::table('customer_usdt_top_up_inbox')->count());
        $this->assertSame('445011487581044736', DB::table('customer_usdt_top_up_inbox')->value('provider_reference'));
        $this->assertSame('pending', DB::table('customer_usdt_top_up_inbox')->value('state'));
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame(0, TelegramOutboundEffect::count());
        $this->assertSame(0, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
    }

    public function test_comma_and_dot_amounts_open_one_confirmed_target(): void
    {
        $actions = app(TelegramUsdtFundingActions::class);
        $dot = $actions->openConfirmedIntent('771050', '771050', 'private', 100, '0.3', CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('confirmed', $dot->outcome);
        $this->assertNotNull($dot->topUp);

        // The same canonical money shows the existing open target instead of
        // opening a second one, including the zero-padded comma form.
        $comma = $actions->openConfirmedIntent('771050', '771050', 'private', 100, '0,3', CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('active_target', $comma->outcome);
        $this->assertSame($dot->topUp->id, $comma->topUp?->id);
        $padded = $actions->openConfirmedIntent('771050', '771050', 'private', 100, '0.300000', CustomerUsdtFundingRail::BinancePay);
        $this->assertSame('active_target', $padded->outcome);
        $this->assertSame($dot->topUp->id, $padded->topUp?->id);
        $this->assertSame(300_000, (int) $dot->topUp->amount_micro);
        $this->assertSame(300_000, (int) $dot->topUp->payable_micro);

        foreach (['0,3,4', '1,000,000', '1,2.3', '1.2,3', '0,1234567', '-0,3', '1e2'] as $invalid) {
            $rejected = $actions->openConfirmedIntent('771050', '771050', 'private', 100, $invalid, CustomerUsdtFundingRail::BinancePay);
            $this->assertSame('invalid_amount', $rejected->outcome, $invalid);
        }
        $this->assertSame(1, CustomerUsdtTopUp::count());
        $this->assertSame(1, CustomerUsdtFundingIntent::count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame(0, TelegramOutboundEffect::count());
    }

    public function test_check_renders_durable_status_when_the_provider_fails(): void
    {
        $target = $this->open('0.3');
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
            ->push(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target)]])
            ->push(['code' => '000000', 'success' => true, 'data' => [$this->transaction($target)]]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 902]])]);

        // The Check callback must not let a provider failure escape into the
        // webhook update: no exception leaves the handler, and the check has no
        // checking placeholder of its own.
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

        // Recovery resolves the same durably leased work once the provider
        // answers again, including after the failed check.
        $this->travel(3)->minutes();
        $this->assertTrue(app(BinanceUsdtTopUpSettlement::class)->check($target->id));
        $this->assertSame('settled', app(UsdtTopUpStatusReader::class)->for($target)->state);
        $this->assertSame(300_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_uid_only_receiver_is_copyable_in_the_chooser_and_stays_snapshotted_after_confirmation(): void
    {
        config(['services.binance.pay_id' => '', 'services.binance.pay_uid' => '11223344']);
        $target = $this->open('0.3');
        $chooser = app(TelegramMenuIntents::class)->usdAmountSurface($this->customer, CustomerUsdtFundingRail::BinancePay);
        $this->assertStringContainsString('Binance UID: 11223344', $this->visible($chooser->caption));
        $this->assertStringContainsString('<code>11223344</code>', $chooser->caption);

        config(['services.binance.pay_uid' => '99887766']);
        $status = $this->statusSurface($target);
        $this->assertStringContainsString("Binance UID\n11223344", $this->visible($status->caption));
        $this->assertStringContainsString('<code>11223344</code>', $status->caption);
        $this->assertStringNotContainsString('99887766', $status->caption);
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
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
        $surface = $this->statusSurface($target);
        $this->assertSettledStatus($target, $this->visible($surface->caption));
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

    private function open(string $amount): CustomerUsdtTopUp
    {
        $result = app(TelegramUsdtFundingActions::class)->openConfirmedIntent(
            '771050', '771050', 'private', 100, $amount, CustomerUsdtFundingRail::BinancePay,
        );
        $this->assertSame('confirmed', $result->outcome);
        $this->assertNotNull($result->topUp);

        return $result->topUp;
    }

    private function statusSurface(CustomerUsdtTopUp $target, ?string $notice = null)
    {
        return app(TelegramUsdtBalanceIntents::class)->statusSurface(
            $this->customer,
            $target->fresh(),
            app(UsdtTopUpStatusReader::class)->for($target),
            $notice,
        );
    }

    private function settledTarget(): CustomerUsdtTopUp
    {
        $target = $this->open('0.30');
        $settlement = app(BinanceUsdtTopUpSettlement::class);
        $settlement->submit($this->customer, $target->id, '445011487581044736');
        $this->fakeTransactionHistory([$this->transaction($target)]);
        $this->assertTrue($settlement->check($target->id));
        $this->assertSame('settled', app(UsdtTopUpStatusReader::class)->for($target)->state);

        return $target;
    }

    /** @param list<array<string, mixed>> $transactions */
    private function fakeTransactionHistory(array $transactions): void
    {
        Http::fake([
            '*sapi/v1/pay/transactions*' => Http::response(['code' => '000000', 'success' => true, 'data' => $transactions]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]]),
        ]);
    }

    /** @return array<string, mixed> */
    private function transaction(CustomerUsdtTopUp $target): array
    {
        return [
            'orderId' => '445011487581044736',
            'transactionId' => 'TX-445011487581044736',
            'amount' => UsdtMicroAmount::formatUnits((int) $target->payable_micro),
            'currency' => 'USDT',
            'transactionTime' => now()->getTimestampMs(),
            'orderType' => 'C2C',
            'receiverInfo' => ['accountId' => '12345678'],
        ];
    }

    private function assertSettledStatus(CustomerUsdtTopUp $target, string $copy): void
    {
        $this->assertStringContainsString('Nominal: 0.3 USDT', $copy);
        $this->assertStringContainsString('Verified received: 0.3 USDT', $copy);
        $this->assertStringContainsString('Credited: 0.3 USD', $copy);
        $this->assertStringContainsString('Balance before credit: 0 USD', $copy);
        $this->assertStringContainsString('Balance after credit: 0.3 USD', $copy);
        $this->assertStringContainsString('The USDT balance was credited according to the ledger.', $copy);
        $this->assertStringContainsString('Status: Balance credited', $copy);
        $this->assertStringContainsString($target->reference, $copy);
        $this->assertStringNotContainsString('TX-445011487581044736', $copy);
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
