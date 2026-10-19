<?php

namespace Tests\Feature\Telegram;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramMessageRouter;
use App\Services\Telegram\TelegramUsdtActivityToken;
use App\Services\Telegram\TelegramUsdtFundingSession;
use App\Services\UsdtBalance\CustomerUsdtActivityCursor;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\SharedTopUpReceiverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\Support\TelegramBotTestSupport;

final class UsdtBalanceMenuTest extends TelegramBotTestSupport
{
    private const CHAT_ID = 554001;

    protected Customer $customer;

    protected User $owner;

    protected BscUsdtRailRpcFixture $rpc;

    private int $updateId = 7000;

    private bool $replaceNextEdit = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCanonicalTelegram();
        $this->rpc = new BscUsdtRailRpcFixture;
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);

        $this->customer = Customer::factory()->create(['telegram_id' => self::CHAT_ID, 'locale' => 'id']);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        ManualPaymentSetting::create([
            'is_active' => true,
            'payment_mode' => 'manual',
            'bsc_min_confirmations' => 3,
            'bsc_payment_ttl_minutes' => 15,
        ]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        app(SharedTopUpReceiverRegistry::class)->activate($this->owner, BscUsdtRailRpcFixture::ADDRESS, 'Shared top-up');
    }

    #[DataProvider('decimalAmounts')]
    public function test_decimal_amount_survives_navigation_root_replacement(string $amount): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('custom', 'balance:usd:binance_pay:x', $root);
        $this->assertSame(TelegramNavigationScreen::UsdtFundingAmount, $root->fresh()->screen);
        $this->replaceNextEdit = true;
        $this->tap('rail', 'balance:usd:binance_pay', $root);
        $this->assertNotSame($root->message_id, $root->fresh()->message_id);

        $this->tap('custom-2', 'balance:usd:binance_pay:x', $root->fresh());
        $this->sendText($amount);
        $target = CustomerUsdtTopUp::query()->sole();
        $this->assertSame(300_000, (int) $target->amount_micro);
        $this->assertSame(300_000, (int) $target->payable_micro);
        $this->assertSame('binance_pay', $target->rail);
        $this->assertSame('confirmed', DB::table('customer_usdt_funding_intents')->value('state'));
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertNoMoney();
    }

    public static function decimalAmounts(): array
    {
        return [['0.3'], ['0,3']];
    }

    public function test_invalid_amounts_keep_prompt_usable_even_after_replacement(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('custom', 'balance:usd:binance_pay:x', $root);
        $this->replaceNextEdit = true;
        foreach (['0', '-0.3', '1e2', '1,2.3', '1.2,3', '1,000,000', '9223372036854.775808', '0.1234567'] as $invalid) {
            $this->sendText($invalid);
            $this->assertSame(0, CustomerUsdtTopUp::query()->count(), $invalid);
            $this->assertStringContainsString(
                'Masukkan nominal USDT positif yang valid dengan maksimal 6 angka desimal.',
                $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)),
                $invalid,
            );
        }
        $this->sendText('0,3');
        $this->assertSame(300_000, (int) CustomerUsdtTopUp::query()->sole()->amount_micro);
        $this->assertNoMoney();
    }

    public function test_lost_amount_cache_requires_explicit_restart_without_guessing_rail(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('custom', 'balance:usd:binance_pay:x', $root);
        Cache::flush();
        $this->sendText('0.3');
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Sesi nominal ini sudah tidak berlaku. Pilih metode top-up lagi ya.'));
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('Pilih metode top-up:', $copy);

        $root = $root->fresh();
        $this->tap('restart', 'balance:usd:binance_pay', $root);
        $this->tap('restart-custom', 'balance:usd:binance_pay:x', $root);
        $this->sendText('0,3');
        $this->assertSame(300_000, (int) CustomerUsdtTopUp::query()->sole()->amount_micro);
        $this->assertNoMoney();
    }

    public function test_stale_amount_root_requires_restart_and_cannot_create_a_target(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('custom', 'balance:usd:binance_pay:x', $root);
        $root->update(['message_id' => 999999]);
        $this->sendText('0.3');
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Sesi nominal ini sudah tidak berlaku. Pilih metode top-up lagi ya.'));
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->count());
        $this->assertNoMoney();
    }

    #[DataProvider('amountNavigation')]
    public function test_amount_prompt_cannot_survive_navigation_or_accept_foreign_private_input(string $action): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('custom', 'balance:usd:binance_pay:x', $root);
        app(TelegramMessageRouter::class)->route($this->customer, '554099', ['text' => '0.3']);
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        if ($action === '/batal') {
            $this->sendText($action);
        } else {
            $this->tap('leave-amount', $action, $root);
        }
        $this->sendText('0.3');
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertNoMoney();
    }

    public static function amountNavigation(): array
    {
        return [['menu:home'], ['menu:back'], ['balance:usd:binance_pay'], ['/batal']];
    }

    public function test_missing_binance_receiver_does_not_offer_transfer_or_amount_prompt(): void
    {
        $this->enableBinance();
        config(['services.binance.pay_id' => '', 'services.binance.pay_uid' => '']);
        $root = $this->startRoot();
        $this->tap('methods', 'balance:add', $root);
        $callbacks = $this->callbacks($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertNotContains('balance:usd:binance_pay', $callbacks);
        $this->assertContains('balance:usd:bsc', $callbacks);
        $this->assertStringNotContainsString('Binance Pay', json_encode($this->lastCanonicalEdit(self::CHAT_ID)['reply_markup'], JSON_THROW_ON_ERROR));

        $this->tap('rail', 'balance:usd:binance_pay', $root);
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringNotContainsString('Kirim nominal', $copy);
        $this->assertStringNotContainsString('<code>', $copy);
        $this->assertNull(app(TelegramUsdtFundingSession::class)->promptedAmount($this->customer));
        $this->sendText('0.3');
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertNoMoney();
    }

    public function test_wallet_shows_both_balances_and_merged_recent_activity(): void
    {
        $this->travel(-5)->hours();
        $this->seedStoreBalance(30_000, 'wallet:1');
        $this->travel(1)->hours();
        $this->seedStoreBalance(10_000, 'wallet:2');
        $this->travel(1)->hours();
        $this->seedUsdtBalance(500_000, 'wallet:3');
        $this->travel(1)->hours();
        $this->seedStoreBalance(20_000, 'wallet:4');
        $this->travel(1)->hours();
        $this->seedUsdtBalance(250_000, 'wallet:5');

        $root = $this->startRoot();
        $this->tap('open-balance', 'balance', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);

        $this->assertStringContainsString('💰 Saldo Saya', $copy);
        $this->assertStringContainsString('🇮🇩 Saldo Rupiah: Rp 60.000', $copy);
        $this->assertStringContainsString('💎 Saldo USDT: 0.75 USDT', $copy);
        $this->assertStringContainsString('Aktivitas terbaru', $copy);

        // Newest first, both ledgers merged, and the overview is capped at four rows.
        $this->assertStringContainsString('1. Kredit top-up · +0.25 USDT · Dikreditkan', $copy);
        $this->assertStringContainsString('2. Top-up · +Rp 20.000 · Dikreditkan', $copy);
        $this->assertStringContainsString('3. Kredit top-up · +0.5 USDT · Dikreditkan', $copy);
        $this->assertStringContainsString('4. Top-up · +Rp 10.000 · Dikreditkan', $copy);
        $this->assertStringNotContainsString('Rp 30.000', $copy);
        $this->assertLessThan(strpos($copy, '+Rp 20.000'), strpos($copy, '+0.25 USDT'));
        $this->assertLessThan(strpos($copy, '+0.5 USDT'), strpos($copy, '+Rp 20.000'));
        $this->assertLessThan(strpos($copy, '+Rp 10.000'), strpos($copy, '+0.5 USDT'));

        $keyboard = $edit['reply_markup']['inline_keyboard'];
        $this->assertSame(['➕ Tambah Saldo'], array_column($keyboard[0], 'text'));
        $this->assertSame(['balance:add'], array_column($keyboard[0], 'callback_data'));
        $this->assertSame(['menu:back', 'menu:home'], array_column($keyboard[1], 'callback_data'));
        $this->assertCount(2, $keyboard, 'The wallet history lives in the caption, not on extra buttons.');
        $this->assertSame(1, $this->routineMessageCount(self::CHAT_ID));
        foreach (['customer_usdt_funding_reservations', 'customer_usdt_top_up_work', 'customer_usdt_top_up_settlement_work', 'telegram_outbound_effects'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_wallet_unavailable_usdt_keeps_history_but_hides_usdt_funding(): void
    {
        $this->seedUsdtBalance(1_500_000);
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $this->customer->id)
            ->update(['balance_micro' => 999_999]);
        $root = $this->startRoot();

        $this->tap('drift-balance', 'balance', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);

        $this->assertStringContainsString('💎 Saldo USDT belum bisa diverifikasi', $copy);
        $this->assertStringNotContainsString('0 USDT', $copy);
        $this->assertStringNotContainsString('1.5 USDT', $copy);
        $markup = json_encode($edit['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('usdt:history', $markup);
        $this->assertStringNotContainsString('balance:activity', $markup);

        $this->tap('drift-methods', 'balance:add', $root);
        $addEdit = $this->lastCanonicalEdit(self::CHAT_ID);
        $callbacks = $this->callbacks($addEdit);
        $this->assertContains('balance:currency:idr', $callbacks);
        $this->assertNotContains('balance:usd:binance_pay', $callbacks);
        $this->assertNotContains('balance:usd:bsc', $callbacks);
        $this->assertStringContainsString('Top-up USDT sementara tidak tersedia.', $this->editCopy($addEdit));
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame(0, DB::table('telegram_outbound_effects')->count());
    }

    public function test_frozen_usdt_account_is_shown_and_blocks_new_funding(): void
    {
        $this->seedUsdtBalance(1_500_000);
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $this->customer->id)
            ->update(['frozen_reason' => 'usdt_reorg_incident']);
        $root = $this->startRoot();

        $this->tap('frozen-balance', 'balance', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $markup = json_encode($edit['reply_markup'], JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('💎 Saldo USDT dibekukan', $copy);
        $this->assertStringNotContainsString('Saldo USDT: 1.5', $copy);
        $this->assertStringNotContainsString('usdt:history', $markup);
        $this->assertStringNotContainsString('balance:activity', $markup);

        $this->tap('frozen-methods', 'balance:add', $root);
        $addEdit = $this->lastCanonicalEdit(self::CHAT_ID);
        $callbacks = $this->callbacks($addEdit);
        $this->assertContains('balance:currency:idr', $callbacks);
        $this->assertNotContains('balance:usd:binance_pay', $callbacks);
        $this->assertNotContains('balance:usd:bsc', $callbacks);
        $this->assertStringContainsString('Top-up USDT sementara tidak tersedia.', $this->editCopy($addEdit));
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame(0, DB::table('telegram_outbound_effects')->count());
    }

    public function test_balance_read_is_fresh_and_never_served_from_a_shared_cache(): void
    {
        $root = $this->startRoot();
        $this->seedUsdtBalance(1_000_000);
        $this->tap('fresh-1', 'balance', $root);
        $this->assertStringContainsString('💎 Saldo USDT: 1 USDT', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));

        $this->seedUsdtBalance(500_000, 'seed:2');
        $this->tap('fresh-2', 'balance', $root);
        $this->assertStringContainsString('💎 Saldo USDT: 1.5 USDT', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
    }

    public function test_method_chooser_offers_only_the_rails_the_store_can_accept(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();

        $this->tap('methods', 'balance:add', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $this->assertStringContainsString('➕ Tambah Saldo', $copy);
        $this->assertStringContainsString('🇮🇩 Saldo Rupiah: Rp 0', $copy);
        $this->assertStringContainsString('💎 Saldo USDT: 0 USDT', $copy);
        $this->assertStringContainsString('Pilih metode top-up:', $copy);
        $this->assertStringNotContainsString('Top-up USDT sementara tidak tersedia.', $copy);
        $this->assertSame([
            ['balance:currency:idr'],
            ['balance:usd:binance_pay'],
            ['balance:usd:bsc'],
            ['menu:back', 'menu:home'],
        ], array_map(
            static fn (array $row): array => array_column($row, 'callback_data'),
            $edit['reply_markup']['inline_keyboard'],
        ));
        $this->assertSame(['🇮🇩 QRIS (Rupiah)'], array_column($edit['reply_markup']['inline_keyboard'][0], 'text'));

        // Each rail disappears with its own gate; the other stays reachable.
        config(['services.binance.pay_id' => '', 'services.binance.pay_uid' => '']);
        $this->tap('methods-gate-binance', 'balance:add', $root);
        $this->assertSame(['balance:currency:idr', 'balance:usd:bsc', 'menu:back', 'menu:home'],
            $this->flatCallbacks($this->lastCanonicalEdit(self::CHAT_ID)));

        app(SharedTopUpReceiverRegistry::class)->deactivate($this->owner);
        $this->tap('methods-no-rails', 'balance:add', $root);
        $this->assertSame(['balance:currency:idr', 'menu:back', 'menu:home'],
            $this->flatCallbacks($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertStringContainsString('Top-up USDT sementara tidak tersedia.', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertNoMoneyOrOutbound();
    }

    public function test_method_chooser_hides_qris_when_the_idr_balance_is_unavailable(): void
    {
        $this->enableBinance();
        $this->seedStoreBalance(25_000, 'idr:drift');
        CustomerBalanceAccount::query()->where('customer_id', $this->customer->id)->update(['balance' => 0]);
        $root = $this->startRoot();

        $this->tap('open-balance', 'balance', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $this->assertStringContainsString('Saldo sementara tidak tersedia', $copy);
        $this->assertStringContainsString('Aktivitas disembunyikan sementara', $copy);
        $callbacks = $this->flatCallbacks($edit);
        $this->assertNotContains('balance:add', $callbacks);
        $this->assertNotContains('balance:activity', $callbacks);
        $this->assertNotContains('usdt:history', $callbacks);

        $this->tap('methods', 'balance:add', $root);
        $callbacks = $this->flatCallbacks($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertNotContains('balance:currency:idr', $callbacks);
        $this->assertContains('balance:usd:binance_pay', $callbacks);
        $this->assertContains('balance:usd:bsc', $callbacks);
        $this->assertSame([], DB::table('customer_usdt_funding_intents')->get()->all());
    }

    public function test_usd_amount_chooser_uses_the_owner_managed_presets(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('methods', 'balance:add', $root);
        $this->tap('rail', 'balance:usd:binance_pay', $root);

        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $this->assertStringContainsString('💵 Nominal top-up USD', $copy);
        $this->assertStringContainsString('Metode: 💎 Binance Pay (USD)', $copy);
        $this->assertStringContainsString('Binance Pay ID: 12345678', $copy);
        $this->assertStringContainsString('Aset: USDT. 1 USDT mengisi 1 USD saldo toko; biaya aplikasi: 0 USDT.', $copy);
        $this->assertStringContainsString('Pilih nominal top-up:', $copy);
        $this->assertStringContainsString('<code>12345678</code>', (string) ($edit['caption'] ?? ''));
        $this->assertSame(['1 USD', '5 USD', '10 USD'], array_column($edit['reply_markup']['inline_keyboard'][0], 'text'));
        $this->assertSame(['25 USD', '50 USD', '100 USD'], array_column($edit['reply_markup']['inline_keyboard'][1], 'text'));
        $this->assertSame(['✍️ Nominal lain'], array_column($edit['reply_markup']['inline_keyboard'][2], 'text'));
        $this->assertSame(['balance:usd:binance_pay:x'], array_column($edit['reply_markup']['inline_keyboard'][2], 'callback_data'));
        $this->assertSame(['balance:add', 'menu:home'], array_column($edit['reply_markup']['inline_keyboard'][3], 'callback_data'));
        $this->assertSame('⬅️ Kembali', $edit['reply_markup']['inline_keyboard'][3][0]['text']);

        foreach (array_slice($edit['reply_markup']['inline_keyboard'], 0, 2) as $row) {
            foreach ($row as $button) {
                $this->assertMatchesRegularExpression('/\Abalance:usd:binance_pay:p:1:[A-Za-z0-9_-]{11}\z/', $button['callback_data']);
                $this->assertStringNotContainsString('1000000', $button['callback_data']);
            }
        }

        CustomerUsdtFundingPolicy::current()->update([
            'preset_amounts_micro' => [2_000_000, 7_500_000],
            'version' => 2,
        ]);
        $this->tap('methods-2', 'balance:add', $root);
        $this->tap('rail-2', 'balance:usd:binance_pay', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $this->assertSame(['2 USD', '7.5 USD'], array_column($edit['reply_markup']['inline_keyboard'][0], 'text'));
        $this->assertMatchesRegularExpression('/\Abalance:usd:binance_pay:p:2:[A-Za-z0-9_-]{11}\z/', $edit['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->tap('preset', $edit['reply_markup']['inline_keyboard'][0][0]['callback_data'], $root);
        $target = CustomerUsdtTopUp::query()->sole();
        $this->assertSame(2_000_000, (int) $target->amount_micro);
        $this->assertSame(2_000_000, (int) $target->payable_micro);
        $this->assertStringContainsString('Kirim tepat: 2 USDT', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertNoMoney();
    }

    public function test_preset_tap_confirms_exactly_one_target_for_the_exact_amount(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('methods', 'balance:add', $root);
        $this->tap('rail', 'balance:usd:binance_pay', $root);
        $preset = $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), '1 USD');
        $this->tap('preset', $preset, $root);

        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $target = CustomerUsdtTopUp::query()->sole();

        $this->assertStringContainsString('💎 Top-up via Binance Pay', $copy);
        $this->assertStringContainsString('🧾 Invoice: usdt:', $copy);
        $this->assertStringContainsString('Kirim tepat: 1 USDT', $copy);
        $this->assertStringContainsString('Masuk saldo: 1 USD', $copy);
        $this->assertStringNotContainsString('Nominal:', $copy);
        $this->assertStringContainsString("Binance Pay ID\n12345678", $copy);
        $this->assertStringContainsString('Bayar dalam ', $copy);
        $this->assertStringContainsString('Tempel Order ID Binance di chat ini setelah bayar.', $copy);
        $this->assertStringContainsString('Status: Menunggu pembayaran', $copy);
        $this->assertStringContainsString('🧾 Invoice: usdt:'.$target->id, $copy);
        $this->assertStringContainsString('<code>12345678</code>', (string) ($edit['caption'] ?? ''));
        $this->assertSame('usdt:'.$target->id, $target->reference);
        $this->assertSame('binance_pay', $target->rail);
        $this->assertSame(1_000_000, (int) $target->amount_micro);
        $this->assertSame(1_000_000, (int) $target->payable_micro);
        $this->assertSame('confirmed', DB::table('customer_usdt_funding_intents')->value('state'));
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame('ready', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->assertSame(1, DB::table('customer_usdt_top_up_settlement_work')->count());
        $this->assertSame([
            ['usdt:check:'.$target->id, 'usdt:order:'.$target->id],
            ['usdt:close:'.$target->id],
            ['menu:back', 'menu:home'],
        ], array_map(
            static fn (array $row): array => array_column($row, 'callback_data'),
            $edit['reply_markup']['inline_keyboard'],
        ));
        $this->assertNoMoney();

        // Confirmation is durable: the same button shows the same open target.
        $this->tap('preset-replay', $preset, $root);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame($target->id, CustomerUsdtTopUp::query()->sole()->id);
        $this->assertStringContainsString('Masih ada top-up berjalan', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));

        $this->tap('check', 'usdt:check:'.$target->id, $root);
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('Status diperbarui dari catatan durabel', $copy);
        $this->assertStringContainsString('Status: Menunggu pembayaran', $copy);
        $this->assertSame([], $this->rpc->calls);
        $this->assertNoMoney();
    }

    public function test_stale_preset_re_renders_chooser_and_creates_nothing(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->tap('methods', 'balance:add', $root);
        $this->tap('rail', 'balance:usd:binance_pay', $root);
        $stale = $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), '1 USD');

        CustomerUsdtFundingPolicy::current()->update(['version' => 2]);
        $this->tap('stale-version', $stale, $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $this->assertStringContainsString('Pilihan nominal sudah berubah. Daftar terbaru ditampilkan.', $copy);
        $this->assertStringContainsString('Pilih metode top-up:', $copy);
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->count());
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());

        // Same policy version, but the Owner removed the amount the button names.
        $this->tap('methods-2', 'balance:add', $root);
        $this->tap('rail-2', 'balance:usd:binance_pay', $root);
        $removed = $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), '5 USD');
        CustomerUsdtFundingPolicy::current()->update(['preset_amounts_micro' => [1_000_000, 10_000_000]]);
        $this->tap('stale-list', $removed, $root);
        $this->assertStringContainsString(
            'Pilihan nominal sudah berubah. Daftar terbaru ditampilkan.',
            $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)),
        );
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->count());
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertNoMoneyOrOutbound();
    }

    public function test_open_target_wins_over_a_new_preset_amount(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $open = $this->openBinanceTarget($root, '1 USD');

        $this->tap('methods-again', 'balance:add', $root);
        $this->tap('rail-again', 'balance:usd:binance_pay', $root);
        $five = $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), '5 USD');
        $this->tap('five', $five, $root);

        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('Kirim tepat: 1 USDT', $copy);
        $this->assertStringContainsString('Masuk saldo: 1 USD', $copy);
        $this->assertStringContainsString('Masih ada top-up berjalan; selesaikan atau tunggu batas waktunya.', $copy);
        $this->assertStringNotContainsString('5 USDT', $copy);
        $this->assertStringNotContainsString('5 USD', $copy);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame($open->id, CustomerUsdtTopUp::query()->sole()->id);
        $this->assertSame(1_000_000, (int) CustomerUsdtTopUp::query()->sole()->amount_micro);
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame(1, DB::table('customer_usdt_funding_intents')->count());
        $this->assertNoMoney();
    }

    public function test_open_target_on_another_rail_names_both_rails(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $open = $this->openSharedTarget('1.5');

        $this->tap('methods-again', 'balance:add', $root);
        $this->tap('binance-rail', 'balance:usd:binance_pay', $root);
        $five = $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), '5 USD');
        $this->tap('binance-five', $five, $root);

        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('💎 Top-up via USDT BEP20', $copy);
        $this->assertStringContainsString('Kamu memilih 💎 Binance Pay (USD), tetapi masih ada top-up ⛓️ USDT BEP20 (USD) (1.5 USDT)', $copy);
        $this->assertStringContainsString("Alamat penerima\n0x", $copy);
        $this->assertStringNotContainsString('Kirim tepat: 5 USDT', $copy);
        $this->assertStringNotContainsString('Masuk saldo: 5 USDT', $copy);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame($open->id, CustomerUsdtTopUp::query()->sole()->id);
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertNoMoney();
    }

    public function test_open_target_wins_over_a_typed_custom_amount(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $open = $this->openBinanceTarget($root, '1 USD');

        $this->tap('methods-again', 'balance:add', $root);
        $this->tap('custom', 'balance:usd:binance_pay:x', $root);
        $this->sendText('3');

        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('Kirim tepat: 1 USDT', $copy);
        $this->assertStringContainsString('Masih ada top-up berjalan; selesaikan atau tunggu batas waktunya.', $copy);
        $this->assertStringNotContainsString('3 USDT', $copy);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame($open->id, CustomerUsdtTopUp::query()->sole()->id);
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertNull(app(TelegramUsdtFundingSession::class)->promptedAmount($this->customer));
        $this->assertNoMoney();
    }

    public function test_binance_order_id_paste_checks_then_records_hint_without_credit(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $target = $this->openBinanceTarget($root, '1 USD');
        config(['services.binance.pay_id' => '87654321']);
        $before = $this->editCount(self::CHAT_ID);

        $this->sendText('445011487581044736');

        $edits = Http::recorded(fn (Request $request): bool => $this->isEditRequest($request)
            && (string) ($request['chat_id'] ?? '') === (string) self::CHAT_ID)->slice($before)->values();
        $this->assertCount(2, $edits, 'The checking placeholder and the durable result each edit the same canonical message.');
        $checking = $edits[0][0]->data();
        $result = $edits[1][0]->data();
        $this->assertSame((int) $checking['message_id'], (int) $result['message_id']);
        $checkingCopy = $this->editCopy($checking);
        $this->assertStringContainsString('⏳ Memeriksa pembayaran…', $checkingCopy);
        $this->assertStringContainsString('Sistem sedang mencocokkan Order ID Binance dengan riwayat pembayaran. Tunggu sebentar ya.', $checkingCopy);
        $this->assertStringNotContainsString('Nominal:', $checkingCopy);
        $this->assertSame(['inline_keyboard' => []], $checking['reply_markup']);

        $resultCopy = $this->editCopy($result);
        $this->assertStringContainsString('Order ID diterima sebagai petunjuk', $resultCopy);
        $this->assertStringContainsString('Kirim tepat: 1 USDT', $resultCopy);
        $this->assertStringContainsString("Binance Pay ID\n12345678", $resultCopy);
        $this->assertStringNotContainsString('87654321', $resultCopy);
        $this->assertSame(1, DB::table('customer_usdt_top_up_inbox')->count());
        $this->assertSame($target->id, DB::table('customer_usdt_top_up_inbox')->value('top_up_id'));
        $this->assertSame('445011487581044736', DB::table('customer_usdt_top_up_inbox')->value('provider_reference'));
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());

        // An invalid format is a local retry that never consumes the hint budget.
        $this->tap('order-prompt', 'usdt:order:'.$target->id, $root);
        $this->sendText('abcd');
        $this->assertStringContainsString('Binance Order ID tidak valid', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertSame(1, DB::table('customer_usdt_top_up_inbox')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_transaction_hash_hint_records_only_a_lookup(): void
    {
        $target = $this->openSharedTarget();
        $root = TelegramNavigationRoot::query()->sole();

        $this->tap('hash-prompt', 'usdt:hash:'.$target->id, $root);
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('Tempel TXID atau link BscScan di chat ini setelah transfer.', $copy);

        $this->sendText('not-a-hash');
        $this->assertStringContainsString('Format tidak valid', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertSame(0, DB::table('customer_usdt_top_up_inbox')->count());
        $this->assertSame([], $this->rpc->calls);

        $hash = '0x'.str_repeat('ab', 32);
        $before = $this->editCount(self::CHAT_ID);
        $this->sendText($hash);
        $edits = Http::recorded(fn (Request $request): bool => $this->isEditRequest($request)
            && (string) ($request['chat_id'] ?? '') === (string) self::CHAT_ID)->slice($before)->values();
        $this->assertCount(2, $edits);
        $this->assertStringContainsString('⏳ Memeriksa pembayaran…', $this->editCopy($edits[0][0]->data()));
        $this->assertStringContainsString('Hash diterima sebagai petunjuk', $this->editCopy($edits[1][0]->data()));
        $this->assertSame(1, DB::table('customer_usdt_top_up_inbox')->count());
        $this->assertSame($hash, DB::table('customer_usdt_top_up_inbox')->value('tx_hash'));
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame([], $this->rpc->calls);
        $this->assertNoMoney();
    }

    public function test_history_lists_ledger_entries_and_open_targets_with_status_buttons(): void
    {
        $this->seedUsdtBalance(1_500_000);
        $target = $this->openSharedTarget();
        $root = TelegramNavigationRoot::query()->sole();

        $this->tap('history', 'usdt:history', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);

        $this->assertStringContainsString('🧾 Riwayat saldo USDT', $copy);
        $this->assertStringContainsString('Kredit top-up · Dikreditkan', $copy);
        $this->assertStringContainsString('+1.5 USDT', $copy);
        $this->assertStringContainsString('Top-up USDT · Menunggu pembayaran', $copy);
        $this->assertStringContainsString('Diminta: 1.5 USDT', $copy);
        $this->assertStringContainsString('usdt:'.$target->id, $copy);

        $markup = json_encode($edit['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('usdt:status:'.$target->id, $markup);

        $this->assertSame([], $this->rpc->calls);
        $this->assertEntriesUnchanged(1);
    }

    public function test_history_keyset_paging_walks_entries_and_targets_in_one_stream(): void
    {
        $this->seedUsdtBalance(1_000_000, 'page:1');
        $this->seedUsdtBalance(500_000, 'page:2');
        $this->seedUsdtBalance(250_000, 'page:3');
        $target = $this->openSharedTarget();

        $activities = app(CustomerUsdtActivityService::class);
        $first = $activities->firstPage($this->customer, 2);
        $this->assertSame(2, count($first->items));
        $this->assertTrue($first->hasOlder);
        $this->assertFalse($first->hasNewer);
        $this->assertNull($first->newerCursor);

        $seen = [];
        $signed = [];
        $pages = [$first];
        $cursor = $first->olderCursor;
        while ($cursor !== null) {
            $page = $activities->olderPage($this->customer, $cursor, 2);
            $this->assertNotNull($page);
            $pages[] = $page;
            $cursor = $page->hasOlder ? $page->olderCursor : null;
        }
        $this->assertCount(2, $pages, 'Four rows at page size two need exactly two pages.');

        foreach ($pages as $index => $page) {
            $this->assertTrue($page->hasNewer === ($index > 0));
            foreach ($page->items as $item) {
                $key = $item->type.':'.$item->cursorId;
                $this->assertArrayNotHasKey($key, $seen, 'A row must never repeat across pages.');
                $seen[$key] = true;
                if ($item->signedMicro !== null) {
                    $signed[] = UsdtMicroAmount::formatUnits($item->signedMicro);
                } else {
                    $this->assertSame('target', $item->type);
                    $this->assertSame((string) $target->id, $item->targetId);
                }
            }
        }
        $this->assertCount(4, $seen, 'Every row appears exactly once across the pages.');
        $this->assertSame(['0.25', '0.5', '1'], $signed);

        $back = $activities->newerPage($this->customer, $pages[1]->newerCursor, 2);
        $this->assertNotNull($back);
        $this->assertSame(
            array_map(static fn ($item): string => $item->type.':'.$item->cursorId, $pages[0]->items),
            array_map(static fn ($item): string => $item->type.':'.$item->cursorId, $back->items),
        );
    }

    public function test_history_service_is_customer_scoped_and_bounded_to_25_default_100_max(): void
    {
        $this->seedUsdtBalance(1_500_000);
        $activities = app(CustomerUsdtActivityService::class);

        $page = $activities->firstPage($this->customer);
        $this->assertSame(1, count($page->items));
        $this->assertSame(CustomerUsdtActivityService::DEFAULT_PAGE_SIZE, 25);
        $this->assertSame(CustomerUsdtActivityService::MAX_PAGE_SIZE, 100);

        $other = Customer::factory()->create(['telegram_id' => 554002, 'locale' => 'id']);
        $this->assertTrue($activities->firstPage($other)->isEmpty());

        foreach ([0, 101] as $limit) {
            try {
                $activities->firstPage($this->customer, $limit);
                $this->fail("Limit {$limit} must be rejected.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_foreign_status_and_cursor_callbacks_are_rejected_without_leaking(): void
    {
        $this->seedUsdtBalance(1_500_000);
        $target = $this->openSharedTarget();
        $root = TelegramNavigationRoot::query()->sole();

        $intruder = Customer::factory()->create(['telegram_id' => 554003, 'locale' => 'id']);
        $this->postTelegramWebhook($this->messageUpdate($this->nextUpdateId(), '/start', ['id' => 554003], 554003))->assertOk();
        $intruderRoot = TelegramNavigationRoot::query()->where('customer_id', $intruder->id)->sole();

        $this->postTelegramWebhook($this->callbackUpdate(
            $this->nextUpdateId(),
            'foreign-status',
            'usdt:status:'.$target->id,
            ['id' => 554003],
            554003,
            'private',
            $intruderRoot->message_id,
        ))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'foreign-status'
            && str_contains((string) ($request['text'] ?? ''), 'usang'));
        $copy = $this->editCopy($this->lastCanonicalEdit(554003));
        $this->assertStringNotContainsString($target->reference, $copy);
        $this->assertStringNotContainsString('1.5 USDT', $copy);
        $this->assertStringContainsString('Sesi ini sudah usang', $copy);

        $entry = DB::table('customer_usdt_balance_entries')->first();
        $token = app(TelegramUsdtActivityToken::class)->cursorToken(
            (int) $this->customer->id,
            CustomerUsdtActivityCursor::entry((int) $entry->id, CarbonImmutable::parse($entry->created_at)),
        );
        $this->postTelegramWebhook($this->callbackUpdate(
            $this->nextUpdateId(),
            'foreign-cursor',
            'usdt:h:o:'.$token,
            ['id' => 554003],
            554003,
            'private',
            $intruderRoot->message_id,
        ))->assertOk();

        $copy = $this->editCopy($this->lastCanonicalEdit(554003));
        $this->assertStringNotContainsString('Kredit top-up', $copy);
        $this->assertStringNotContainsString('+1.5 USDT', $copy);
        $this->assertSame([], $this->rpc->calls);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertEntriesUnchanged(1);
        $this->assertSame(0, DB::table('telegram_outbound_effects')->count());
        $this->assertSame(1, $this->routineMessageCount(554003));
        $this->assertSame($intruderRoot->message_id, $intruderRoot->fresh()->message_id);
        $this->assertSame($root->message_id, $root->fresh()->message_id);
    }

    public function test_legacy_confirm_and_cancel_callbacks_render_the_stale_surface(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $this->openBinanceTarget($root, '1 USD');
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());

        foreach (['usdt:confirm:'.Str::uuid(), 'usdt:cancel:'.Str::uuid()] as $index => $callback) {
            $this->tap('legacy-'.$index, $callback, $root);
            $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
            $this->assertStringContainsString('Sesi ini sudah usang. Mulai lagi dari menu Saldo.', $copy);
            $this->assertStringContainsString('Pilih metode top-up:', $copy);
        }
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertNoMoney();
    }

    public function test_usdt_command_chooser_lists_both_rails(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();

        $this->sendText('/usdt');
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $this->assertStringContainsString('💵 Top-up USD', $copy);
        $this->assertStringContainsString('Pilih metode top-up:', $copy);
        $this->assertSame([
            ['balance:usd:binance_pay'],
            ['balance:usd:bsc'],
            ['menu:back', 'menu:home'],
        ], array_map(
            static fn (array $row): array => array_column($row, 'callback_data'),
            $edit['reply_markup']['inline_keyboard'],
        ));
        $this->assertSame(1, $this->routineMessageCount(self::CHAT_ID));
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->count());
        $this->assertSame($root->message_id, $root->fresh()->message_id);
        $this->assertNoMoney();
    }

    public function test_usdt_command_amount_opens_one_shared_target_without_money_effect(): void
    {
        $root = $this->startRoot();

        $this->sendText('/usdt 1.5');
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $target = CustomerUsdtTopUp::query()->sole();

        $this->assertStringContainsString('💎 Top-up via USDT BEP20', $copy);
        $this->assertStringContainsString('🧾 Invoice: usdt:', $copy);
        $this->assertStringContainsString('Masuk saldo: 1.5 USDT', $copy);
        $this->assertStringContainsString('Kirim tepat: 1.5 USDT', $copy);
        $this->assertStringNotContainsString('Nominal:', $copy);
        $this->assertStringContainsString('Kirim hanya USDT BEP20 ke alamat ini.', $copy);
        $this->assertStringContainsString("Alamat penerima\n0x", $copy);
        $this->assertStringContainsString('Tempel TXID atau link BscScan di chat ini setelah transfer.', $copy);
        $this->assertStringContainsString('Status: Menunggu pembayaran', $copy);
        $this->assertStringContainsString('🧾 Invoice: usdt:'.$target->id, $copy);
        $this->assertStringNotContainsString('Rp', $copy);

        $this->assertSame('bsc_shared', $target->rail);
        $this->assertSame(1_500_000, (int) $target->amount_micro);
        $this->assertSame(1_500_000, (int) $target->payable_micro);
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_work')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_settlement_work')->count());
        $this->assertSame([
            ['usdt:check:'.$target->id, 'usdt:hash:'.$target->id],
            ['usdt:close:'.$target->id],
            ['menu:back', 'menu:home'],
        ], array_map(
            static fn (array $row): array => array_column($row, 'callback_data'),
            $edit['reply_markup']['inline_keyboard'],
        ));
        $this->assertSame(1, $this->routineMessageCount(self::CHAT_ID));
        $this->assertSame($root->message_id, $root->fresh()->message_id);
        $this->assertNoMoney();

        // A second amount request shows the open target instead of reusing or
        // silently replacing the requested amount.
        $this->sendText('/usdt@lhiestore_bot 2');
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('Kirim tepat: 1.5 USDT', $copy);
        $this->assertStringContainsString('Masih ada top-up berjalan; selesaikan atau tunggu batas waktunya.', $copy);
        $this->assertStringNotContainsString('Kirim tepat: 2 USDT', $copy);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame($target->id, CustomerUsdtTopUp::query()->sole()->id);
        $this->assertNoMoney();
    }

    public function test_usdt_command_rejects_invalid_amounts_locally(): void
    {
        $this->startRoot();

        foreach (['0.0000001', 'abc', '1,000,000', '1,2.3', '1.2,3', '0,1234567', '1 000,3', '-0.3'] as $invalid) {
            $this->sendText('/usdt '.$invalid);
            $this->assertStringContainsString(
                'Masukkan nominal USDT positif yang valid dengan maksimal 6 angka desimal.',
                $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)),
                $invalid,
            );
        }
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->count());
        $this->assertSame(1, $this->routineMessageCount(self::CHAT_ID));
        $this->assertNoMoneyOrOutbound();
    }

    public function test_single_comma_is_a_decimal_separator_not_a_grouping_separator(): void
    {
        $this->startRoot();

        // `1,000` stays the decimal form of 1.000 USDT; grouping would need a
        // second separator, which the strict parser rejects.
        $this->sendText('/usdt 1,000');
        $target = CustomerUsdtTopUp::query()->sole();
        $this->assertSame('bsc_shared', $target->rail);
        $this->assertSame(1_000_000, (int) $target->amount_micro);
        $this->assertStringContainsString('Kirim tepat: 1 USDT', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertSame(1, $this->routineMessageCount(self::CHAT_ID));
        $this->assertNoMoney();
    }

    public function test_group_chat_never_renders_balance_or_history(): void
    {
        $root = $this->startRoot();

        $this->postTelegramWebhook($this->callbackUpdate(
            $this->nextUpdateId(),
            'group-usdt-topup',
            'balance:usdt',
            ['id' => self::CHAT_ID],
            -100554001,
            'group',
            $root->message_id,
        ))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'group-usdt-topup'
            && ($request['text'] ?? null) === 'Demi keamanan, lanjutkan di chat pribadi dengan bot.');
        $this->assertSame(0, $this->editCount(-100554001));

        $this->postTelegramWebhook($this->messageUpdate(
            $this->nextUpdateId(),
            '/usdt 1.5',
            ['id' => self::CHAT_ID],
            -100554001,
            'group',
        ))->assertOk();

        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertSame(0, DB::table('customer_usdt_funding_intents')->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '-100554001'
            && str_contains((string) ($request['text'] ?? ''), 'chat pribadi'));
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('walletLocaleScreens')]
    public function test_wallet_choosers_and_instructions_are_localized(string $locale, array $expected): void
    {
        $this->enableBinance();
        $this->customer->update(['locale' => $locale]);
        $root = $this->startRoot();

        $this->tap('balance', 'balance', $root);
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        foreach ($expected['wallet'] as $needle) {
            $this->assertStringContainsString($needle, $copy);
        }
        $walletButtons = array_merge(...array_map(
            static fn (array $row): array => array_column($row, 'text'),
            $this->lastCanonicalEdit(self::CHAT_ID)['reply_markup']['inline_keyboard'],
        ));
        foreach ($expected['buttons'] as $needle) {
            $this->assertContains($needle, $walletButtons);
        }

        $this->tap('add', 'balance:add', $root);
        $this->assertStringContainsString($expected['methods'], $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));

        $this->tap('rail', 'balance:usd:binance_pay', $root);
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        foreach ($expected['amount'] as $needle) {
            $this->assertStringContainsString($needle, $copy);
        }

        $this->tap('preset', $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), '1 USD'), $root);
        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        foreach ($expected['status'] as $needle) {
            $this->assertStringContainsString($needle, $copy);
        }
        $this->assertNoMoney();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, list<string>>}>
     */
    public static function walletLocaleScreens(): array
    {
        return [
            'indonesian' => ['id', [
                'wallet' => ['💰 Saldo Saya', '🇮🇩 Saldo Rupiah: Rp 0', 'Aktivitas terbaru'],
                'buttons' => ['➕ Tambah Saldo'],
                'methods' => 'Pilih metode top-up:',
                'amount' => ['💵 Nominal top-up USD', 'Metode: 💎 Binance Pay (USD)', 'Pilih nominal top-up:'],
                'status' => ['💎 Top-up via Binance Pay', '🧾 Invoice: usdt:', 'Kirim tepat: 1 USDT', 'Masuk saldo: 1 USD', 'Status: Menunggu pembayaran', 'Tempel Order ID Binance di chat ini setelah bayar.'],
            ]],
            'english' => ['en', [
                'wallet' => ['💰 My Balance', '🇮🇩 IDR balance: Rp 0', 'Recent activity'],
                'buttons' => ['➕ Add Balance'],
                'methods' => 'Choose a top-up method:',
                'amount' => ['💵 USD top-up amount', 'Method: 💎 Binance Pay (USD)', 'Choose a top-up amount:'],
                'status' => ['💎 Binance Pay top-up', '🧾 Invoice: usdt:', 'Send exactly: 1 USDT', 'Balance credit: 1 USD', 'Status: Awaiting payment', 'Then paste your Binance Order ID in this chat.'],
            ]],
            'malay' => ['ms', [
                'wallet' => ['💰 Baki Saya', '🇮🇩 Baki Rupiah: Rp 0', 'Aktiviti terkini'],
                'buttons' => ['➕ Tambah Baki'],
                'methods' => 'Pilih kaedah tambah baki:',
                'amount' => ['💵 Jumlah tambah baki USD', 'Kaedah: 💎 Binance Pay (USD)', 'Pilih jumlah tambah baki:'],
                'status' => ['💎 Tambah baki melalui Binance Pay', '🧾 Invois: usdt:', 'Hantar tepat: 1 USDT', 'Kredit baki: 1 USD', 'Status: Menunggu bayaran', 'Kemudian tampal Order ID Binance dalam chat ini.'],
            ]],
        ];
    }

    private function enableBinance(): void
    {
        config(['services.binance.api_key' => 'test', 'services.binance.api_secret' => 'test',
            'services.binance.pay_id' => '12345678', 'services.binance.pay_uid' => '']);
        ManualPaymentSetting::query()->update(['binance_pay_enabled' => true]);
    }

    private function startRoot(): TelegramNavigationRoot
    {
        $this->postTelegramWebhook($this->messageUpdate($this->nextUpdateId(), '/start', [], self::CHAT_ID))->assertOk();

        return TelegramNavigationRoot::query()->sole();
    }

    private function sendText(string $text): void
    {
        $this->postTelegramWebhook($this->messageUpdate($this->nextUpdateId(), $text, [], self::CHAT_ID))->assertOk();
    }

    private function tap(string $callbackId, string $data, TelegramNavigationRoot $root): void
    {
        $this->postTelegramWebhook($this->callbackUpdate(
            $this->nextUpdateId(),
            $callbackId,
            $data,
            ['id' => self::CHAT_ID],
            self::CHAT_ID,
            'private',
            $root->message_id,
        ))->assertOk();
    }

    public function test_start_cancels_the_unpaid_target_and_allows_a_new_amount(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $old = $this->openBinanceTarget($root, '1 USD');

        $this->sendText('/start');

        $this->assertSame('released', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame('cancelled_by_customer', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->assertSame(0, DB::table('customer_usdt_funding_reservations')->whereNull('resolved_at')->count());

        $root = TelegramNavigationRoot::query()->sole();
        $this->tap('methods-again', 'balance:add', $root);
        $this->tap('binance-again', 'balance:usd:binance_pay', $root);
        $five = $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), '5 USD');
        $this->tap('five-again', $five, $root);

        $fresh = CustomerUsdtTopUp::query()->where('id', '!=', $old->id)->sole();
        $this->assertSame(5_000_000, (int) $fresh->amount_micro);
        $this->assertStringContainsString('Kirim tepat: 5 USDT', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->whereNull('resolved_at')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertNoMoney();
    }

    public function test_cancel_button_closes_the_target_and_returns_to_the_amount_chooser(): void
    {
        $this->enableBinance();
        $root = $this->startRoot();
        $target = $this->openBinanceTarget($root, '1 USD');

        $this->tap('cancel-target', 'usdt:close:'.$target->id, $root);

        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        $this->assertStringContainsString('Top-up sebelumnya dibatalkan. Pilih nominal baru.', $copy);
        $this->assertStringContainsString('💵 Nominal top-up USD', $copy);
        $this->assertSame('released', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame('cancelled_by_customer', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertNoMoney();

        // Replaying the cancel is idempotent: one resolution, no second target.
        $this->tap('cancel-again', 'usdt:close:'.$target->id, $root);
        $this->assertStringContainsString('Top-up sebelumnya dibatalkan', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertSame(1, DB::table('customer_usdt_top_up_resolutions')->count());
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
    }

    private function openBinanceTarget(TelegramNavigationRoot $root, string $label): CustomerUsdtTopUp
    {
        $this->tap('methods-'.$label, 'balance:add', $root);
        $this->tap('rail-'.$label, 'balance:usd:binance_pay', $root);
        $this->tap('preset-'.$label, $this->presetCallback($this->lastCanonicalEdit(self::CHAT_ID), $label), $root);

        return CustomerUsdtTopUp::query()->sole();
    }

    private function openSharedTarget(string $amount = '1.5'): CustomerUsdtTopUp
    {
        $root = TelegramNavigationRoot::query()->first() ?? $this->startRoot();
        $this->tap('shared-open', 'balance:add', $root);
        $this->tap('shared-rail', 'balance:usd:bsc', $root);
        $this->tap('shared-custom', 'balance:usd:bsc:x', $root);
        $this->sendText($amount);

        return CustomerUsdtTopUp::query()->sole();
    }

    private function presetCallback(array $edit, string $label): string
    {
        foreach ($edit['reply_markup']['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                if (($button['text'] ?? null) === $label) {
                    return $button['callback_data'];
                }
            }
        }
        $this->fail("Preset button {$label} was not rendered.");
    }

    /** @return list<string> */
    private function callbacks(array $edit): array
    {
        return array_merge(...array_map(
            static fn (array $row): array => array_column($row, 'callback_data'),
            $edit['reply_markup']['inline_keyboard'],
        ));
    }

    /** @return list<string> */
    private function flatCallbacks(array $edit): array
    {
        $callbacks = [];
        foreach ($edit['reply_markup']['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button['callback_data'];
            }
        }

        return $callbacks;
    }

    private function seedUsdtBalance(int $micro, string $key = 'seed:1'): void
    {
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $this->customer,
            new VerifiedUsdtFunding(
                (int) $this->customer->id,
                UsdtMicroAmount::fromUnits($micro),
                (string) Str::uuid(),
                'receipt-'.$key,
                '1',
            ),
            $key,
        );
    }

    private function seedStoreBalance(int $amount, string $key): void
    {
        app(StoreBalanceLedger::class)->credit(
            $this->customer,
            $amount,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            $key,
            'balance_top_up:'.$key,
        );
    }

    private function nextUpdateId(): int
    {
        return $this->updateId++;
    }

    private function fakeCanonicalTelegram(): void
    {
        $nextMessageId = 700;
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => $nextMessageId, 'photo' => [['file_id' => 'banner-'.$nextMessageId]]],
                ]);
            },
            'https://api.telegram.org/*/sendMessage' => function () use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response(['ok' => true, 'result' => ['message_id' => $nextMessageId]]);
            },
            'https://api.telegram.org/*' => function (Request $request) {
                if ($this->replaceNextEdit && $this->isEditRequest($request)) {
                    $this->replaceNextEdit = false;

                    return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found'], 400);
                }

                return Http::response(['ok' => true]);
            },
        ]);
    }

    private function lastCanonicalEdit(int $chatId): array
    {
        $edits = Http::recorded(fn (Request $request): bool => $this->isEditRequest($request)
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);
        $this->assertNotEmpty($edits, "Expected a canonical edit for chat {$chatId}.");

        return $edits->last()[0]->data();
    }

    private function editCount(int $chatId): int
    {
        return Http::recorded(fn (Request $request): bool => $this->isEditRequest($request)
            && (string) ($request['chat_id'] ?? '') === (string) $chatId)->count();
    }

    private function routineMessageCount(int $chatId): int
    {
        return Http::recorded(fn (Request $request): bool => (str_ends_with($request->url(), '/sendMessage')
                || str_ends_with($request->url(), '/sendPhoto'))
            && (string) ($request['chat_id'] ?? '') === (string) $chatId)->count();
    }

    private function isEditRequest(Request $request): bool
    {
        return str_ends_with($request->url(), '/editMessageCaption')
            || str_ends_with($request->url(), '/editMessageText');
    }

    private function editCopy(array $edit): string
    {
        return html_entity_decode(strip_tags((string) ($edit['caption'] ?? $edit['text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** A confirmed funding target never moves money on its own. */
    private function assertNoMoney(): void
    {
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_accounting_events')->count());
        $this->assertSame(0, DB::table('telegram_outbound_effects')->count());
        $this->assertSame([], $this->rpc->calls);
    }

    private function assertNoMoneyOrOutbound(): void
    {
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_accounting_events')->count());
        foreach (['customer_usdt_funding_reservations', 'customer_usdt_top_up_work', 'customer_usdt_top_up_settlement_work', 'telegram_outbound_effects'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame([], $this->rpc->calls);
    }

    private function assertEntriesUnchanged(int $expected): void
    {
        $this->assertSame($expected, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame($expected, DB::table('customer_usdt_balance_accounting_events')->count());
    }
}
