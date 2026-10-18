<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscTransactionInput;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\SharedTopUpReceiverRegistry;
use App\Services\UsdtFunding\UsdtFundingIntentResult;
use App\Services\UsdtFunding\UsdtFundingIntentService;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

final class SharedBscTopUpTest extends TestCase
{
    private BscUsdtRailRpcFixture $rpc;

    private Customer $customer;

    private TelegramNavigationRoot $root;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00:00 UTC'));
        Http::preventStrayRequests();
        Queue::fake();

        $this->rpc = new BscUsdtRailRpcFixture;
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->customer = Customer::factory()->create(['telegram_id' => 961001]);
        $this->root = $this->rootFor($this->customer, 200);

        ManualPaymentSetting::create([
            'is_active' => true,
            'payment_mode' => 'manual',
            'bsc_min_confirmations' => 3,
            'bsc_payment_ttl_minutes' => 15,
        ]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        app(SharedTopUpReceiverRegistry::class)->activate($this->owner, BscUsdtRailRpcFixture::ADDRESS, 'Shared top-up');
    }

    public function test_shared_invoice_allocates_exact_amount_and_requires_txid(): void
    {
        $preview = $this->preview($this->customer);
        $this->assertSame('opened', $preview->outcome);
        $this->assertSame(1_000_000, (int) $preview->intent->amount_micro);
        $this->assertSame(1_000_000, (int) $preview->intent->payment_snapshot['requested_micro']);
        $this->assertSame(BscUsdtRailRpcFixture::ADDRESS, $preview->intent->payment_snapshot['receiver_address']);
        $this->assertSame(CustomerUsdtFundingRail::BscShared->value, $preview->intent->rail);

        $confirmed = $this->confirm($this->customer, $this->root, $preview->intent);
        $this->assertSame('confirmed', $confirmed->outcome);
        $target = $confirmed->topUp;
        $this->assertNull($target->receiver_id);
        $this->assertNotNull($target->shared_receiver_id);
        $this->assertSame('ready', DB::table('customer_usdt_top_up_work')->where('top_up_id', $target->id)->value('state'));
        $this->assertSame('awaiting_payment', DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->value('state'));

        // No credit before an authoritative transaction hint.
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_bscscan_link_settles_exactly_once(): void
    {
        $preview = $this->preview($this->customer);
        $target = $this->confirm($this->customer, $this->root, $preview->intent)->topUp;
        $settlement = app(UsdtTopUpSettlement::class);

        $settlement->submit($this->customer, $target->id, 'https://bscscan.com/tx/'.BscUsdtRailRpcFixture::TX);
        $this->assertTrue($settlement->check($target->id));

        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_entries')->sum('amount_micro'));
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->assertSame('settled', DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $target->id)->value('state'));
        $this->assertSame(1, DB::table('external_money_receipts')->count());

        // Replay of the same hint never creates a second credit.
        $settlement->submit($this->customer, $target->id, BscUsdtRailRpcFixture::TX);
        $settlement->check($target->id);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_open_invoices_never_share_a_payable_amount(): void
    {
        $other = Customer::factory()->create(['telegram_id' => 961002]);
        $otherRoot = $this->rootFor($other, 201);

        $first = $this->preview($this->customer);
        $second = $this->preview($other, $otherRoot);
        $this->assertSame('opened', $first->outcome);
        $this->assertSame('opened', $second->outcome);
        $this->assertNotSame((int) $first->intent->amount_micro, (int) $second->intent->amount_micro);
        $this->assertSame(1_000_001, (int) $second->intent->amount_micro);
        $this->assertSame(1_000_000, (int) $second->intent->payment_snapshot['requested_micro']);
    }

    public function test_rotating_the_shared_address_invalidates_a_pending_preview(): void
    {
        $preview = $this->preview($this->customer);
        $this->assertSame('opened', $preview->outcome);

        $registry = app(SharedTopUpReceiverRegistry::class);
        $registry->activate($this->owner, '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'Rotated');

        $claimed = $this->confirm($this->customer, $this->root, $preview->intent);
        $this->assertSame('stale_terms', $claimed->outcome);
        $this->assertSame(0, CustomerUsdtTopUp::query()->count());
    }

    public function test_second_invoice_cannot_reuse_the_same_txid(): void
    {
        $firstPreview = $this->preview($this->customer);
        $first = $this->confirm($this->customer, $this->root, $firstPreview->intent)->topUp;
        app(UsdtTopUpSettlement::class)->submit($this->customer, $first->id, BscUsdtRailRpcFixture::TX);
        $this->assertTrue(app(UsdtTopUpSettlement::class)->check($first->id));

        $other = Customer::factory()->create(['telegram_id' => 961003]);
        $otherRoot = $this->rootFor($other, 202);
        $secondPreview = $this->preview($other, $otherRoot);
        $second = $this->confirm($other, $otherRoot, $secondPreview->intent)->topUp;
        $this->assertSame(1_000_001, (int) $second->amount_micro);

        // Make the synthetic chain evidence match the second invoice amount.
        $log = $this->rpc->log(100, 0, '1000001000000000000', BscUsdtRailRpcFixture::ADDRESS);
        $this->rpc->receipt = [
            'status' => '0x1', 'transactionHash' => BscUsdtRailRpcFixture::TX,
            'blockNumber' => '0x64', 'blockHash' => BscUsdtRailRpcFixture::blockHash(100), 'logs' => [$log],
        ];

        app(UsdtTopUpSettlement::class)->submit($other, $second->id, BscUsdtRailRpcFixture::TX);
        app(UsdtTopUpSettlement::class)->check($second->id);

        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $second->id)->value('state'));
    }

    public function test_bscscan_link_parser_rejects_other_hosts_and_paths(): void
    {
        $this->assertSame(BscUsdtRailRpcFixture::TX, BscTransactionInput::normalize('https://bscscan.com/tx/'.BscUsdtRailRpcFixture::TX));
        $this->assertSame(BscUsdtRailRpcFixture::TX, BscTransactionInput::normalize('bscscan.com/tx/'.BscUsdtRailRpcFixture::TX));
        $this->assertNull(BscTransactionInput::normalize('https://example.com/tx/'.BscUsdtRailRpcFixture::TX));
        $this->assertNull(BscTransactionInput::normalize('https://bscscan.com/address/'.BscUsdtRailRpcFixture::TX));
        $this->assertNull(BscTransactionInput::normalize('not-a-hash'));
    }

    private function preview(Customer $customer, ?TelegramNavigationRoot $root = null): UsdtFundingIntentResult
    {
        $root ??= $this->root;
        $chatId = (string) $customer->telegram_id;

        return app(UsdtFundingIntentService::class)->open(
            $customer,
            $chatId,
            (int) $root->id,
            (int) $root->message_id,
            UsdtMicroAmount::fromDecimalString('1'),
            CustomerUsdtFundingRail::BscShared,
        );
    }

    private function confirm(Customer $customer, TelegramNavigationRoot $root, CustomerUsdtFundingIntent $intent): UsdtFundingIntentResult
    {
        return app(UsdtFundingIntentService::class)->claim(
            $customer,
            (string) $customer->telegram_id,
            (int) $root->id,
            (int) $root->message_id,
            (string) $intent->nonce,
            'confirm',
        );
    }

    private function rootFor(Customer $customer, int $messageId): TelegramNavigationRoot
    {
        return TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id,
            'chat_id' => (string) $customer->telegram_id,
            'message_id' => $messageId,
            'media_kind' => TelegramNavigationMediaKind::Text,
            'screen' => TelegramNavigationScreen::Home,
            'locale' => 'id',
            'generation' => 1,
        ]);
    }
}
