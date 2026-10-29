<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Models\BscPaymentReceiver;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscTransactionInput;
use App\Services\Bsc\BscUniquePaymentAmount;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
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
use Illuminate\Support\Str;
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

    public function test_verified_checkout_address_can_receive_shared_top_ups_and_settle_once(): void
    {
        $receiver = BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);
        $shared = app(SharedTopUpReceiverRegistry::class)->activate($this->owner, $receiver->address, 'Checkout and top-up');
        $this->assertSame($receiver->address_claim_id, $shared->address_claim_id);
        $this->assertSame('checkout', $receiver->addressClaim->channel->value);
        $this->rpc->receipt['logs'] = [$this->rpc->log(receiver: $receiver->address)];
        $target = $this->confirm($this->customer, $this->root, $this->preview($this->customer)->intent)->topUp;
        $settlement = app(UsdtTopUpSettlement::class);
        $settlement->submit($this->customer, $target->id, BscUsdtRailRpcFixture::TX);
        $this->assertTrue($settlement->check($target->id));
        $settlement->check($target->id);
        $this->assertDatabaseHas('customer_usdt_balance_accounts', ['customer_id' => $this->customer->id, 'balance_micro' => 1000000]);
        $this->assertDatabaseCount('customer_usdt_balance_entries', 1);
        $this->assertDatabaseCount('external_money_receipts', 1);
    }

    public function test_top_up_amount_avoids_existing_checkout_including_nonzero_funding_fee(): void
    {
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 100000]);
        $payment = Payment::factory()->create(['provider' => 'manual', 'method' => 'USDT_BEP20', 'currency' => 'USDT', 'amount' => 1100000]);
        PaymentBscDetail::factory()->forPayment($payment)->create();
        $preview = $this->preview($this->customer);
        $this->assertSame('opened', $preview->outcome);
        $this->assertSame(1000001, $preview->intent->amount_micro);
        $this->assertSame(1100001, $preview->intent->payable_micro);
    }

    public function test_checkout_amount_avoids_pending_and_confirmed_shared_top_up_amounts(): void
    {
        $preview = $this->preview($this->customer);
        $this->assertSame(1001000, app(BscUniquePaymentAmount::class)->allocate(1000000));
        $this->confirm($this->customer, $this->root, $preview->intent);
        $this->assertSame(1001000, app(BscUniquePaymentAmount::class)->allocate(1000000));
    }

    public function test_unverified_checkout_address_cannot_be_enabled_for_shared_top_up(): void
    {
        $receiver = BscPaymentReceiver::factory()->active()->create();
        $this->expectException(\InvalidArgumentException::class);
        app(SharedTopUpReceiverRegistry::class)->activate($this->owner, $receiver->address);
    }

    public function test_checkout_receipt_cannot_also_fund_a_shared_top_up(): void
    {
        $target = $this->confirm($this->customer, $this->root, $this->preview($this->customer)->intent)->topUp;
        $receipt = new VerifiedMoneyReceipt(
            MoneyReceiptIdentity::bscLog(56, BscUsdtRailRpcFixture::TX, 0),
            'customer:'.$this->customer->id, 'USDT', '1000000000000000000', 18,
            MoneyReceiptTarget::orderPayment((string) Str::uuid()),
            ['receiver_address' => BscUsdtRailRpcFixture::ADDRESS],
            parentClaim: MoneyReceiptParentClaim::for(56, BscUsdtRailRpcFixture::TX),
        );
        DB::transaction(fn () => app(ExternalMoneyReceiptClaims::class)->claim($receipt));
        $settlement = app(UsdtTopUpSettlement::class);
        $settlement->submit($this->customer, $target->id, BscUsdtRailRpcFixture::TX);
        $settlement->check($target->id);
        $this->assertDatabaseCount('customer_usdt_balance_entries', 0);
        $this->assertDatabaseHas('customer_usdt_top_up_settlement_work', ['top_up_id' => $target->id, 'state' => 'manual_review']);
        $this->assertDatabaseHas('external_money_receipts', ['target_type' => 'order_payment']);
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
