<?php

namespace Tests\Feature;

use App\Exceptions\InvalidCheckout;
use App\Models\BscPaymentReceiver;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Checkout\BscCheckoutPaymentPreparation;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPaymentPreparationResolver;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Storefront\StorefrontProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BscReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    private object $rpc;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.bsc.readiness_cache_seconds', 60);

        $this->rpc = new class implements BscVerificationRpcClientInterface
        {
            public int $calls = 0;

            public int $transactionBaseline = 0;

            public int $callsUnderCheckoutTransaction = 0;

            public bool $disableSettingOnFirstCall = false;

            public bool $metadataAvailable = true;

            public int $chainIdValue = 56;

            public int $tokenDecimalsValue = 18;

            private bool $settingDisabled = false;

            public function chainId(): int
            {
                $this->recordCall();

                if (! $this->metadataAvailable) {
                    throw new BscRpcException('RPC is unavailable.', retryable: true);
                }

                return $this->chainIdValue;
            }

            public function contractCode(string $contract): string
            {
                $this->recordCall();

                if (! $this->metadataAvailable) {
                    throw new BscRpcException('RPC is unavailable.', retryable: true);
                }

                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                $this->recordCall();

                if (! $this->metadataAvailable) {
                    throw new BscRpcException('RPC is unavailable.', retryable: true);
                }

                return $this->tokenDecimalsValue;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                return true;
            }

            public function transactionReceipt(string $transactionHash): ?array
            {
                throw new BscRpcException('Not used by readiness.');
            }

            public function blockByNumber(string|int $blockNumber): ?array
            {
                throw new BscRpcException('Not used by readiness.');
            }

            private function recordCall(): void
            {
                $this->calls++;
                if (DB::transactionLevel() > $this->transactionBaseline) {
                    $this->callsUnderCheckoutTransaction++;
                }
                if ($this->disableSettingOnFirstCall && ! $this->settingDisabled) {
                    $this->settingDisabled = true;
                    ManualPaymentSetting::current()?->update(['bsc_payment_enabled' => false]);
                }
            }
        };

        app()->instance(BscRpcClientInterface::class, $this->rpc);

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
            'bsc_payment_enabled' => true,
        ]);

        BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'token_contract' => self::CONTRACT,
        ]);
    }

    public function test_readiness_requires_only_rpc_metadata_for_hash_verification(): void
    {
        $report = app(BscReadiness::class)->check();

        $this->assertTrue($report->isReady());
        $this->assertSame(56, $report->rpcChainId);
        $this->assertSame(18, $report->tokenDecimals);
        $this->assertStringContainsString('TX Hash', $report->configurationMessage());
    }

    public function test_readiness_fails_when_the_rpc_is_down(): void
    {
        $this->rpc->metadataAvailable = false;

        $report = app(BscReadiness::class)->check();

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('BSC_RPC', $report->invalid);
    }

    public function test_readiness_fails_when_token_metadata_is_invalid(): void
    {
        $this->rpc->tokenDecimalsValue = 6;

        $report = app(BscReadiness::class)->check();

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('BSC_USDT_DECIMALS', $report->invalid);
    }

    public function test_readiness_is_cached_and_invalidated_after_setting_and_receiver_mutations(): void
    {
        $readiness = app(BscReadiness::class);

        $readiness->check();
        $readiness->check();
        $this->assertSame(3, $this->rpc->calls);

        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => false]);
        $readiness->check();
        $this->assertSame(3, $this->rpc->calls);

        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => true]);
        BscPaymentReceiver::query()->firstOrFail()->update(['label' => 'Rotated receiver']);
        $readiness->check();

        $this->assertSame(6, $this->rpc->calls);
    }

    public function test_readiness_cache_expires_after_its_short_ttl(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            app(BscReadiness::class)->check();
            Carbon::setTestNow($now->copy()->addSeconds(61));
            app(BscReadiness::class)->check();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(6, $this->rpc->calls);
    }

    public function test_customer_surfaces_keep_bsc_when_receipt_verification_is_available(): void
    {
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $this->assertTrue(app(TelegramCheckoutIntents::class)->bscRailSelectable());
        $this->assertNotNull(
            app(StorefrontProductPresenter::class)->present($product)->checkoutChoice('website_usdt_bep20'),
        );
        $this->assertNotNull(
            app(StorefrontProductPresenter::class)->present($product)->checkoutChoice('website_binance_pay'),
        );
        $this->assertSame(3, $this->rpc->calls);
    }

    public function test_storefront_exposes_both_binance_rails_with_hash_verification(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::current()->update(['binance_pay_enabled' => true]);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertNotNull($presentation->checkoutChoice('website_binance_pay'));
        $this->assertNotNull($presentation->checkoutChoice('website_usdt_bep20'));
        $this->assertStringContainsString('BSC', (string) $presentation->checkoutChoice('website_binance_pay')?->description);
    }

    public function test_bsc_checkout_uses_the_registered_preparation_adapter(): void
    {
        $resolver = app(CheckoutPaymentPreparationResolver::class);
        $intent = CheckoutPaymentIntents::usdtBep20();

        $this->assertInstanceOf(BscCheckoutPaymentPreparation::class, $resolver->for($intent));
    }

    public function test_bsc_checkout_does_not_call_rpc_while_checkout_locks_are_held(): void
    {
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->rpc->transactionBaseline = DB::transactionLevel();

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::usdtBep20(),
        );

        $this->assertSame('USDT_BEP20', $order->payments->sole()->method);
        $this->assertSame(0, $this->rpc->callsUnderCheckoutTransaction);
    }

    public function test_bsc_checkout_proceeds_with_hash_verification(): void
    {
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $this->rpc->transactionBaseline = DB::transactionLevel();

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::usdtBep20(),
        );

        $this->assertSame('USDT_BEP20', $order->payments->sole()->method);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, $this->rpc->callsUnderCheckoutTransaction);
    }

    public function test_stale_bsc_readiness_is_rejected_against_the_locked_setting_snapshot(): void
    {
        $this->rpc->disableSettingOnFirstCall = true;
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $this->rpc->transactionBaseline = DB::transactionLevel();

        try {
            app(OrderCheckoutService::class)->checkout(
                $cart,
                CheckoutPaymentIntents::usdtBep20(),
            );
            $this->fail('Expected stale BSC readiness to reject checkout.');
        } catch (InvalidCheckout $exception) {
            $this->assertStringContainsString('BSC payment rail', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(0, $this->rpc->callsUnderCheckoutTransaction);
    }

    public function test_binance_pay_id_checkout_does_not_preflight_bsc_readiness(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::current()->update(['binance_pay_enabled' => true]);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $this->rpc->transactionBaseline = DB::transactionLevel();

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::binancePayId(),
        );

        $this->assertSame('BINANCE_PAY_ID', $order->payments->sole()->method);
        $this->assertSame(0, $this->rpc->calls);
        $this->assertSame(0, $this->rpc->callsUnderCheckoutTransaction);
    }
}
