<?php

namespace Tests\Feature;

use App\Jobs\VerifyBscPayment;
use App\Models\BscPaymentReceiver;
use App\Models\BscPaymentTransferEvidence;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\Product;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Checkout\PaymentProviderResolver;
use App\Storefront\StorefrontInvoiceUrl;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BinancePaymentRailSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.storefront.telegram_reservation_floor', 0);

        app()->instance(BscRpcClientInterface::class, new class implements BscRpcClientInterface
        {
            public function chainId(): int
            {
                return 56;
            }

            public function contractCode(string $contract): string
            {
                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                return 18;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                return true;
            }
        });

        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::PAKASIR,
            'is_active' => true,
            'binance_pay_enabled' => true,
            'bsc_payment_enabled' => true,
        ]);
    }

    public function test_explicit_binance_rails_bypass_the_provider_switch_and_legacy_alias_keeps_pay_id(): void
    {
        $setting = ManualPaymentSetting::current();

        $payId = app(PaymentProviderResolver::class)->resolve('binance_pay_id', $setting);
        $legacy = app(PaymentProviderResolver::class)->resolve('binance_pay', $setting);
        $bsc = app(PaymentProviderResolver::class)->resolve('usdt_bep20', $setting);

        $this->assertSame(PaymentProvider::MANUAL, $payId->provider);
        $this->assertSame('BINANCE_PAY_ID', $payId->paymentIntent->method());
        $this->assertSame('BINANCE_PAY_ID', $legacy->paymentIntent->method());
        $this->assertSame(PaymentProvider::MANUAL, $bsc->provider);
        $this->assertSame('USDT_BEP20', $bsc->paymentIntent->method());
        $this->assertSame(PaymentProvider::PAKASIR, $bsc->requestedProvider);
    }

    public function test_storefront_can_create_explicit_bsc_checkout_with_one_snapshot(): void
    {
        Queue::fake();
        config()->set('services.binance.pay_id', '987654321');
        $this->activeReceiver('0x1111111111111111111111111111111111111111');
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_buyer',
            'terms_accepted' => '1',
            'payment_method' => 'usdt_bep20',
        ]);

        $response->assertRedirect();
        $order = Order::with('payments')->sole();
        $payment = $order->payments->sole();

        $this->assertSame(PaymentProvider::MANUAL, $payment->provider);
        $this->assertSame('USDT_BEP20', $payment->method);
        $this->assertSame(1, PaymentBscDetail::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, $payment->bscDetail()->count());
    }

    public function test_disabled_bsc_rail_has_no_checkout_side_effects(): void
    {
        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => false]);
        $this->activeReceiver('0x2222222222222222222222222222222222222222');
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_disabled',
            'terms_accepted' => '1',
            'payment_method' => 'usdt_bep20',
        ])->assertRedirect()
            ->assertSessionHas('storefront_checkout_error', fn (string $message): bool => str_contains($message, 'BSC payment rail is not ready.'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_storefront_checkout_identity_includes_the_final_rail(): void
    {
        Queue::fake();
        config()->set('services.binance.pay_id', '987654321');
        $this->activeReceiver('0x3333333333333333333333333333333333333333');
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $session = ['storefront.checkout_client_key' => 'same-rail-selector-session'];

        $this->withSession($session)->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'rail_buyer',
            'terms_accepted' => '1',
            'payment_method' => 'binance_pay_id',
        ])->assertRedirect();

        $this->withSession($session)->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'rail_buyer',
            'terms_accepted' => '1',
            'payment_method' => 'usdt_bep20',
        ])->assertRedirect();

        $this->assertSame(2, Order::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame(1, Payment::query()->where('method', 'BINANCE_PAY_ID')->count());
        $this->assertSame(1, Payment::query()->where('method', 'USDT_BEP20')->count());
    }

    public function test_bsc_invoice_renders_the_snapshotted_amount_address_and_network_warning(): void
    {
        Queue::fake();
        $receiver = $this->activeReceiver('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_invoice',
            'terms_accepted' => '1',
            'payment_method' => 'usdt_bep20',
        ]);
        $order = Order::with('payments.bscDetail')->sole();
        $payment = $order->payments->sole();

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee($receiver->address_normalized)
            ->assertSee('2.5 USDT')
            ->assertDontSee('2.5 USDT USDT')
            ->assertSee('BNB Smart Chain (BEP20) ONLY')
            ->assertSee('ERC20, TRC20, opBNB')
            ->assertSee('Payment deadline:')
            ->assertSee('Submit BSC TX Hash')
            ->assertSee('Check Payment');
    }

    public function test_bsc_invoice_keeps_payment_snapshot_after_receiver_rotation_and_only_links_validated_evidence(): void
    {
        Queue::fake();
        $receiver = $this->activeReceiver('0xdddddddddddddddddddddddddddddddddddddddd');
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_snapshot',
            'terms_accepted' => '1',
            'payment_method' => 'usdt_bep20',
        ]);
        $order = Order::with('payments.bscDetail')->sole();
        $payment = $order->payments->sole();
        $snapshotAddress = (string) $payment->bscDetail->receiver_address;
        $deadline = $payment->expired_at->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i T');

        $receiver->forceFill([
            'address' => '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'address_normalized' => '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
        ])->save();

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee($snapshotAddress)
            ->assertDontSee('0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee')
            ->assertSee('Payment deadline: '.$deadline, false)
            ->assertDontSee('View validated transaction');

        $hash = '0x'.str_repeat('d', 64);
        BscPaymentTransferEvidence::create([
            'payment_id' => $payment->id,
            'tx_hash' => $hash,
            'token_contract' => self::CONTRACT,
            'receiver_address' => $snapshotAddress,
            'from_address' => '0x'.str_repeat('f', 40),
            'amount_units' => '2500000000000000000',
            'block_number' => 100,
            'block_hash' => '0x'.str_repeat('e', 64),
            'block_timestamp' => now(),
            'receipt_status' => 'success',
            'log_index' => 0,
            'confirmations' => 3,
            'verification_status' => PaymentBscDetail::STATUS_CONFIRMED,
            'first_seen_at' => now(),
            'last_verified_at' => now(),
        ]);

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('https://bscscan.com/tx/'.$hash, false)
            ->assertSee('View validated transaction');

        config()->set('services.bsc.explorer_base_url', 'https://evil.example');
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertDontSee('https://evil.example/tx/'.$hash, false)
            ->assertDontSee('View validated transaction');
    }

    public function test_bsc_invoice_keeps_verification_states_distinct_and_safe(): void
    {
        Queue::fake();
        $this->activeReceiver('0xffffffffffffffffffffffffffffffffffffffff');
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_states',
            'terms_accepted' => '1',
            'payment_method' => 'usdt_bep20',
        ]);
        $order = Order::with('payments.bscDetail')->sole();
        $detail = $order->payments->sole()->bscDetail;

        foreach ([
            PaymentBscDetail::STATUS_NOT_FOUND => 'Not found',
            PaymentBscDetail::STATUS_CONFIRMING => 'Confirming',
            PaymentBscDetail::STATUS_PROVIDER_ERROR => 'Provider error',
            PaymentBscDetail::STATUS_MISMATCH => 'Mismatch',
            PaymentBscDetail::STATUS_MANUAL_REVIEW => 'Manual review',
            PaymentBscDetail::STATUS_CONFIRMED => 'Confirmed',
        ] as $status => $label) {
            $detail->forceFill(['verification_status' => $status])->save();

            $this->get($response->headers->get('Location'))
                ->assertOk()
                ->assertSee('Status: '.$label, false);
        }
    }

    public function test_bsc_hash_validation_does_not_consume_attempts_and_valid_hash_queues_verification(): void
    {
        Queue::fake();
        $this->activeReceiver('0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_hash',
            'terms_accepted' => '1',
            'payment_method' => 'usdt_bep20',
        ]);
        $order = Order::with('payments.bscDetail')->sole();
        $payment = $order->payments->sole();
        $url = app(StorefrontInvoiceUrl::class)->bscTxHash($order);

        $this->post($url, ['bsc_tx_hash' => 'not-a-hash'])->assertRedirect();
        $this->assertSame(0, (int) $payment->bscDetail->fresh()->tx_attempts);

        $hash = '0x'.str_repeat('a', 64);
        $this->post($url, ['bsc_tx_hash' => $hash])->assertRedirect();
        $detail = $payment->bscDetail->fresh();
        $this->assertSame(1, (int) $detail->tx_attempts);
        $this->assertSame(strtolower($hash), $detail->current_tx_hash);
        Queue::assertPushed(VerifyBscPayment::class, fn (VerifyBscPayment $job): bool => $job->paymentId === $payment->id && $job->submissionVersion === 1);

        $this->post($url, ['bsc_tx_hash' => strtoupper($hash)])->assertRedirect();
        $this->assertSame(1, (int) $payment->bscDetail->fresh()->tx_attempts);
        $this->assertSame(1, (int) $payment->bscDetail->fresh()->tx_submission_version);
        Queue::assertPushed(VerifyBscPayment::class, 1);
    }

    public function test_storefront_product_modal_exposes_two_explicit_binance_rails(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->activeReceiver('0xcccccccccccccccccccccccccccccccccccccccc');
        Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('websiteBinancePayId', false)
            ->assertSee('websiteUsdtBep20', false)
            ->assertSee('name="payment_choice"', false)
            ->assertSee('name="payment_method"', false)
            ->assertSee('cancelBinanceRailSelection', false)
            ->assertSee('>Cancel<', false);
    }

    private function activeReceiver(string $address): BscPaymentReceiver
    {
        return BscPaymentReceiver::factory()->verified()->active()->create([
            'address' => $address,
            'token_contract' => self::CONTRACT,
        ]);
    }
}
