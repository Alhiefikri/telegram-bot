<?php

namespace Tests\Feature\Telegram;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Telegram\TelegramUsdtActivityToken;
use App\Services\UsdtBalance\CustomerUsdtActivityCursor;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
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
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify(
            $registry->register($this->customer, BscUsdtRailRpcFixture::ADDRESS, $this->owner)->receiver,
            true,
            $this->owner,
        );
    }

    public function test_usd_binance_amount_and_order_id_flow_records_a_durable_lookup(): void
    {
        config(['services.binance.api_key' => 'test', 'services.binance.api_secret' => 'test',
            'services.binance.pay_id' => '12345678', 'services.binance.pay_uid' => '']);
        ManualPaymentSetting::query()->update(['binance_pay_enabled' => true]);
        $root = $this->startRoot();
        $this->tap('currency', 'balance:add', $root);
        $this->tap('usd', 'balance:currency:usd', $root);
        $this->tap('binance', 'balance:usd:binance_pay', $root);
        $this->sendText('1.5');
        $intent = CustomerUsdtFundingIntent::query()->sole();
        $this->assertSame('binance_pay', $intent->rail);
        $this->assertSame(1500000, $intent->amount_micro);
        $this->tap('confirm-binance', 'usdt:confirm:'.$intent->nonce, $root);
        $target = CustomerUsdtTopUp::query()->sole();
        $this->assertSame('ready', DB::table('customer_usdt_top_up_work')->value('state'));
        $this->sendText('445011487581044736');
        $this->assertSame($target->id, DB::table('customer_usdt_top_up_inbox')->value('top_up_id'));
        $this->assertSame('445011487581044736', DB::table('customer_usdt_top_up_inbox')->value('provider_reference'));
        $this->assertEntriesUnchanged(0);
    }

    public function test_private_balance_screen_shows_usdt_balance_topup_and_history(): void
    {
        $this->seedUsdtBalance(1_500_000);
        $root = $this->startRoot();

        $this->tap('open-balance', 'balance', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);

        $this->assertStringContainsString('💰 Saldo Saya', $copy);
        $this->assertStringContainsString('Saldo tersedia: Rp 0', $copy);
        $this->assertStringContainsString('💎 Saldo USDT: 1.5 USDT', $copy);

        $keyboard = $edit['reply_markup']['inline_keyboard'];
        $this->assertSame(['balance:add'], array_column($keyboard[0], 'callback_data'));
        $this->assertSame(['balance:activity'], array_column($keyboard[1], 'callback_data'));
        $this->assertSame(['balance:usdt'], array_column($keyboard[2], 'callback_data'));
        $this->assertSame(['usdt:history'], array_column($keyboard[3], 'callback_data'));
        $this->assertSame(['menu:back', 'menu:home'], array_column($keyboard[4], 'callback_data'));
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

    public function test_mismatched_projection_shows_localized_unavailable_instead_of_a_fake_zero(): void
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
        $this->assertStringNotContainsString('balance:usdt', $markup);
        $this->assertStringContainsString('usdt:history', $markup);
    }

    public function test_frozen_account_shows_frozen_state_and_hides_new_funding(): void
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
        $this->assertStringNotContainsString('1.5 USDT', $copy);
        $this->assertStringNotContainsString('balance:usdt', $markup);
        $this->assertStringContainsString('usdt:history', $markup);
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

        $this->assertSame(0, CustomerUsdtFundingIntent::query()->count());
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '-100554001'
            && str_contains((string) ($request['text'] ?? ''), 'chat pribadi'));
    }

    public function test_usdt_command_preview_is_exact_and_has_no_side_effects(): void
    {
        $root = $this->startRoot();

        $this->sendText('/usdt 1.5');
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);

        $this->assertStringContainsString('💎 Konfirmasi top-up USDT', $copy);
        $this->assertStringContainsString('Kredit diminta: 1.5 USDT', $copy);
        $this->assertStringContainsString('Biaya aplikasi: 0 USDT', $copy);
        $this->assertStringContainsString('Total yang harus dikirim: 1.5 USDT', $copy);
        $this->assertStringContainsString('BNB Smart Chain (BEP20)', $copy);
        $this->assertStringNotContainsString('Rp', $copy);

        $keyboard = $edit['reply_markup']['inline_keyboard'];
        $this->assertMatchesRegularExpression('/\Ausdt:confirm:[a-f0-9]{32}\z/', $keyboard[0][0]['callback_data']);
        $this->assertMatchesRegularExpression('/\Ausdt:cancel:[a-f0-9]{32}\z/', $keyboard[1][0]['callback_data']);

        $intent = CustomerUsdtFundingIntent::query()->sole();
        $this->assertSame('pending', $intent->state);
        $this->assertSame(1_500_000, (int) $intent->amount_micro);
        $this->assertSame(1_500_000, (int) $intent->payable_micro);
        $this->assertSame(1, $this->routineMessageCount(self::CHAT_ID), 'Preview must edit the canonical root only.');
        $this->assertNoMoneyOrOutbound();

        // Replaying the same amount reuses the preview instead of creating a second one.
        $this->sendText('/usdt 1.5');
        $this->assertSame(1, CustomerUsdtFundingIntent::query()->count());
        $this->assertSame($intent->nonce, CustomerUsdtFundingIntent::query()->sole()->nonce);

        // The group-style bot suffix is accepted in the private chat too.
        $this->sendText('/usdt@lhiestore_bot 2');
        $pending = CustomerUsdtFundingIntent::query()->where('state', 'pending')->sole();
        $this->assertSame(2_000_000, (int) $pending->amount_micro);
        $this->assertSame('superseded', $intent->fresh()->state);
    }

    public function test_invalid_amount_is_rejected_locally_without_any_preview(): void
    {
        $root = $this->startRoot();

        $this->sendText('/usdt 0.0000001');
        $this->assertStringContainsString('Nominal USDT tidak valid', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));

        $this->sendText('/usdt abc');
        $this->assertStringContainsString('Nominal USDT tidak valid', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));

        $this->assertSame(0, CustomerUsdtFundingIntent::query()->count());
        $this->assertNoMoneyOrOutbound();
    }

    public function test_confirm_creates_one_target_and_replay_returns_the_same_target(): void
    {
        $root = $this->startRoot();
        $this->sendText('/usdt 1.5');
        $nonce = CustomerUsdtFundingIntent::query()->sole()->nonce;

        $this->tap('confirm-1', 'usdt:confirm:'.$nonce, $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);
        $this->assertStringContainsString('Top-up dikonfirmasi', $copy);
        $this->assertStringContainsString('Status: Menyiapkan tujuan pembayaran', $copy);
        $this->assertStringContainsString('Kredit: 1.5 USDT', $copy);

        $target = CustomerUsdtTopUp::query()->sole();
        $this->assertSame('usdt:'.$target->id, $target->reference);
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_work')->count());
        $this->assertSame(1, DB::table('customer_usdt_top_up_settlement_work')->count());
        $this->assertEntriesUnchanged(0);

        $keyboard = $edit['reply_markup']['inline_keyboard'];
        $this->assertSame('usdt:check:'.$target->id, $keyboard[0][0]['callback_data']);

        $this->tap('confirm-replay', 'usdt:confirm:'.$nonce, $root);
        $this->assertSame(1, CustomerUsdtTopUp::query()->count());
        $this->assertSame($target->id, CustomerUsdtTopUp::query()->sole()->id);
        $this->assertStringContainsString('Top-up dikonfirmasi', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertEntriesUnchanged(0);

        // Refresh and status reads are durable-read only: no RPC and no money.
        $this->tap('check-1', 'usdt:check:'.$target->id, $root);
        $this->assertStringContainsString('Status diperbarui dari catatan durabel', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertStringContainsString('Status: Menyiapkan tujuan pembayaran', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->tap('status-1', 'usdt:status:'.$target->id, $root);
        $this->assertStringContainsString('Kredit: 1.5 USDT', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertSame([], $this->rpc->calls);
        $this->assertEntriesUnchanged(0);
    }

    public function test_cancel_and_stale_callbacks_leave_no_target_or_money(): void
    {
        $root = $this->startRoot();
        $this->sendText('/usdt 1.5');
        $nonce = CustomerUsdtFundingIntent::query()->sole()->nonce;

        $this->tap('cancel-1', 'usdt:cancel:'.$nonce, $root);
        $this->assertStringContainsString('dibatalkan', $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)));
        $this->assertSame('cancelled', CustomerUsdtFundingIntent::query()->sole()->state);
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());

        $this->sendText('/usdt 2');
        $staleNonce = CustomerUsdtFundingIntent::query()->where('state', 'pending')->sole()->nonce;
        $this->postTelegramWebhook($this->callbackUpdate(
            $this->nextUpdateId(),
            'confirm-stale',
            'usdt:confirm:'.$staleNonce,
            ['id' => self::CHAT_ID],
            self::CHAT_ID,
            'private',
            $root->message_id + 1,
        ))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'confirm-stale'
            && str_contains((string) ($request['text'] ?? ''), 'usang'));
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
        $this->assertNoMoneyOrOutbound();
    }

    public function test_transaction_hash_hint_records_only_a_lookup(): void
    {
        $target = $this->confirmedTarget();
        $root = TelegramNavigationRoot::query()->sole();

        $this->tap('hash-prompt', 'usdt:hash:'.$target->id, $root);
        $this->assertStringContainsString(
            'Kirim hash transaksi BSC',
            $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)),
        );

        $this->sendText('not-a-hash');
        $this->assertStringContainsString(
            'Format hash tidak valid',
            $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)),
        );
        $this->assertSame(0, DB::table('customer_usdt_top_up_inbox')->count());
        $this->assertSame([], $this->rpc->calls);

        $hash = '0x'.str_repeat('ab', 32);
        $this->sendText($hash);
        $this->assertStringContainsString(
            'Hash diterima sebagai petunjuk',
            $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID)),
        );
        $this->assertSame(1, DB::table('customer_usdt_top_up_inbox')->count());
        $this->assertSame($hash, DB::table('customer_usdt_top_up_inbox')->value('tx_hash'));
        $this->assertSame(1, DB::table('customer_usdt_funding_reservations')->count());
        $this->assertSame([], $this->rpc->calls);
        $this->assertEntriesUnchanged(0);
    }

    public function test_history_lists_ledger_entries_and_open_targets_with_status_buttons(): void
    {
        $this->seedUsdtBalance(1_500_000);
        $target = $this->confirmedTarget();
        $root = TelegramNavigationRoot::query()->sole();

        $this->tap('history', 'usdt:history', $root);
        $edit = $this->lastCanonicalEdit(self::CHAT_ID);
        $copy = $this->editCopy($edit);

        $this->assertStringContainsString('🧾 Riwayat saldo USDT', $copy);
        $this->assertStringContainsString('Kredit top-up · Dikreditkan', $copy);
        $this->assertStringContainsString('+1.5 USDT', $copy);
        $this->assertStringContainsString('Top-up USDT · Menyiapkan', $copy);
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
        $target = $this->confirmedTarget();

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
        $target = $this->confirmedTarget();
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
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('usdtLocaleScreens')]
    public function test_usdt_screens_are_localized(string $locale, array $expected): void
    {
        $this->customer->update(['locale' => $locale]);
        $this->startRoot();
        $this->sendText('/usdt 1.5');

        $copy = $this->editCopy($this->lastCanonicalEdit(self::CHAT_ID));
        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $copy);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function usdtLocaleScreens(): array
    {
        return [
            'indonesian' => ['id', ['💎 Konfirmasi top-up USDT', 'Kredit diminta: 1.5 USDT', 'Total yang harus dikirim: 1.5 USDT']],
            'english' => ['en', ['💎 Confirm USDT top-up', 'Requested credit: 1.5 USDT', 'Total to send: 1.5 USDT']],
            'malay' => ['ms', ['💎 Sahkan tambah baki USDT', 'Kredit diminta: 1.5 USDT', 'Jumlah untuk dihantar: 1.5 USDT']],
        ];
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

    private function confirmedTarget(): CustomerUsdtTopUp
    {
        $this->startRoot();
        $this->sendText('/usdt 1.5');
        $nonce = CustomerUsdtFundingIntent::query()->sole()->nonce;
        $this->tap('confirm-'.$nonce, 'usdt:confirm:'.$nonce, TelegramNavigationRoot::query()->sole());

        return CustomerUsdtTopUp::query()->sole();
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
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
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
        return (string) ($edit['caption'] ?? $edit['text'] ?? '');
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
