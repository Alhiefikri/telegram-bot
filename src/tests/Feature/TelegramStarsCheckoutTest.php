<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Models\Product;
use App\Services\Admin\AdminPaymentOperations;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\TelegramStarsCheckoutIntent;
use App\Services\Checkout\TelegramStarsConfirmation;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaymentReceiptReport;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsStarsCheckouts;
use Tests\TestCase;

final class TelegramStarsCheckoutTest extends TestCase
{
    use BuildsStarsCheckouts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableStars();
    }

    public function test_direct_creates_exact_xtr_target_without_touching_active_cart_and_replays_after_pause(): void
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct(2, withTier: true);
        $cart = $this->starsCart($buyer, $product);
        $intent = $this->starsIntent($buyer, $product, quantity: 2);
        $service = app(OrderCheckoutService::class);
        $order = $service->checkoutTelegramStarsDirect($product, 2, $intent);
        $payment = $order->payments->sole();
        $item = $order->orderItems->sole();
        $target = $payment->telegramStarsDetail;

        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
        $this->assertSame(1, $cart->cartItems()->sole()->quantity);
        $this->assertSame(['IDR', 10_000, 2, 5_000, 10_000, 2, 0], [
            $order->currency, $order->total_amount, $item->quantity_price_tier_minimum,
            $item->unit_price, $item->subtotal, $item->local_quantity, $item->supplier_quantity,
        ]);
        $this->assertSame(['telegram_stars', 'TELEGRAM_STARS', 'XTR', 50, PaymentStatus::Pending], [
            $payment->provider, $payment->method, $payment->currency, $payment->amount, $payment->status,
        ]);
        $this->assertSame('Telegram Stars', $payment->payment_method_label);
        $this->assertSame([18_300, 13_000, 1_500, 1, 10_000, 50], [
            $target->idr_per_usd, $target->reward_micro_usd, $target->reserve_bps, $target->policy_version,
            $target->subtotal_idr, $target->total_xtr,
        ]);
        $this->assertSame($buyer->id, $target->customer_id);
        $this->assertSame($buyer->telegram_id, $target->telegram_user_id);
        $this->assertSame($buyer->telegram_id, $target->chat_id);
        $this->assertSame($payment->merchant_ref, $target->merchant_ref);
        $this->assertSame($payment->expired_at->getTimestamp(), $target->deadline_epoch);
        $this->assertSame($order->expires_at->getTimestamp(), $target->deadline_epoch);
        $this->assertSame(2, $target->retail_lines[0]['tier_minimum']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $target->invoice_payload);
        $this->assertArrayNotHasKey('invoice_payload', $target->toArray());
        $this->assertSame(2, $item->stockItems()->count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, app(PaymentReceiptReport::class)->idrNetTotalCanonical());
        config(['services.telegram.token' => 'fixture:token']);
        $effects = app(TelegramOutboundEffectService::class);
        $this->assertNull($effects->recordCheckoutInstructions($order, $payment));
        $this->assertNull($effects->recordAdminOrderPendingNotification($order, '123456'));
        $this->assertNotContains(PaymentProvider::TELEGRAM_STARS, PaymentProvider::allowed());
        $this->assertNotContains('telegram_stars', CheckoutPaymentIntents::supportedPaymentRails());

        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false, 'idr_per_usd' => 30_000, 'version' => 2]);
        $this->travel(16)->minutes();
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(0, $item->stockItems()->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $replay = $service->checkoutTelegramStarsDirect($product, 2, $intent);
        $this->assertSame($order->id, $replay->id);
        $this->assertSame($target->invoice_payload, $replay->payments->sole()->telegramStarsDetail->invoice_payload);
        $this->assertSame(1, Order::count());
        $this->assertDatabaseEmpty('telegram_outbound_effects');
        $this->assertNull($effects->recordAdminOrderExpiredNotification($order->fresh(), '123456'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_cart_is_all_local_and_cannot_switch_to_manual_or_settle_without_evidence(): void
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct();
        $cart = $this->starsCart($buyer, $product);
        $cart->cartItems()->create(['product_id' => $this->starsProduct()->id, 'quantity' => 1]);
        $intent = $this->starsIntent($buyer, $cart);
        $service = app(OrderCheckoutService::class);
        $order = $service->checkout($cart, $intent, checkoutKey: 'stars-quote');
        $payment = $order->payments->sole();
        $this->assertCount(2, $order->orderItems);
        $this->assertSame(99, $payment->amount);
        $this->assertFalse(app(AdminPaymentOperations::class)->canReconcile($payment));
        $lifecycle = app(OrderLifecycle::class);
        foreach ([
            fn () => $lifecycle->applyPaymentPaid($payment),
            fn () => $lifecycle->applyPaymentRefunded($payment),
            fn () => $lifecycle->applyManualCompletion($order),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Unverified Stars transition succeeded.');
            } catch (InvalidOrderTransition) {
                $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
            }
        }
        try {
            $service->checkout($cart, CheckoutPaymentIntents::manualQris(), checkoutKey: 'stars-quote');
            $this->fail('Rail switch succeeded.');
        } catch (InvalidCheckout) {
            $this->assertDatabaseCount('payments', 1);
        }
        $lifecycle->applyPendingCancellation($order);
        $this->assertSame($order->id, $service->checkout($cart, $intent, checkoutKey: 'stars-quote')->id);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, PaymentTelegramStarsDetail::count());
    }

    #[DataProvider('staleChanges')]
    public function test_stale_quotes_fail_with_reconfirmation_and_no_effects(string $change): void
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct(3, withTier: true);
        $cart = $this->starsCart($buyer, $product, 2);
        $intent = $this->starsIntent($buyer, $cart);
        match ($change) {
            'price' => $product->quantityPriceTiers()->update(['price_amount' => 5_001]),
            'quantity' => $cart->cartItems()->update(['quantity' => 3]),
            'policy' => DB::table('stars_pricing_policies')->update(['version' => 2, 'issuance_enabled' => false]),
            'revision' => $intent = new TelegramStarsCheckoutIntent($intent->quote, new TelegramStarsConfirmation($buyer->id, $buyer->telegram_id, $buyer->telegram_id, 'private', 'revision-b', 'stars-quote')),
            'expired' => $this->travel(5)->minutes(),
        };
        try {
            app(OrderCheckoutService::class)->checkout($cart, $intent, checkoutKey: 'stars-quote');
            $this->fail('Stale quote succeeded.');
        } catch (CheckoutPaymentUnavailable $e) {
            $this->assertSame('snapshot_changed', $e->reason);
        }
        $this->assertNoCheckout();
    }

    public static function staleChanges(): array
    {
        return array_map(fn ($change) => [$change], ['price', 'quantity', 'policy', 'revision', 'expired']);
    }

    public function test_mixed_supplier_fallback_after_preview_aborts_the_entire_cart(): void
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct(2);
        $cart = $this->starsCart($buyer, $this->starsProduct());
        $cart->cartItems()->create(['product_id' => $product->id, 'quantity' => 2]);
        $intent = $this->starsIntent($buyer, $cart);
        $product->stockItems()->firstOrFail()->update(['retired_at' => now()]);
        $product->forceFill(['supplier_available_stock' => 2, 'supplier_available_until' => now()->addHour()])->save();
        try {
            app(OrderCheckoutService::class)->checkout($cart, $intent, checkoutKey: 'stars-quote');
            $this->fail('Mixed checkout succeeded.');
        } catch (InsufficientStock) {
            $this->assertNoCheckout();
        }
    }

    public function test_numeric_only_stock_is_not_eligible_and_disabled_policy_does_not_quote(): void
    {
        $buyer = Customer::factory()->create();
        try {
            $this->starsIntent($buyer, Product::factory()->create(), quantity: 1);
            $this->fail('Numeric stock quoted.');
        } catch (InsufficientStock) {
            $this->assertNoCheckout();
        }
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $this->expectException(CheckoutPaymentUnavailable::class);
        $this->starsIntent($buyer, $this->starsProduct(), quantity: 1);
    }

    public function test_foreign_buyer_cannot_use_stored_quote(): void
    {
        $buyer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $product = $this->starsProduct();
        $cart = $this->starsCart($buyer, $product);
        $intent = $this->starsIntent($buyer, $cart);
        try {
            app(OrderCheckoutService::class)->checkout($this->starsCart($other, $product), $intent, checkoutKey: 'stars-quote');
            $this->fail('Foreign Cart accepted.');
        } catch (InvalidCheckout) {
            $this->assertNoCheckout();
        }
    }

    public function test_duplicate_unique_emails_after_preview_cannot_defer_credentials(): void
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct(2);
        $product->update(['unique_credential_email' => true]);
        $intent = $this->starsIntent($buyer, $product, quantity: 2);
        $product->stockItems()->orderByDesc('id')->firstOrFail()->update(['secret' => 'fixture0@example.com | other']);

        try {
            app(OrderCheckoutService::class)->checkoutTelegramStarsDirect($product, 2, $intent);
            $this->fail('Checkout accepted deferred credentials.');
        } catch (InsufficientStock) {
            $this->assertNoCheckout();
            $this->assertDatabaseEmpty('carts');
            $this->assertSame(0, $product->fresh()->reserved_stock);
        }
    }

    public function test_paid_reporting_fixture_never_counts_idr_catalog_basis_as_cash(): void
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct();
        $intent = $this->starsIntent($buyer, $product, quantity: 1);
        $order = app(OrderCheckoutService::class)->checkoutTelegramStarsDirect($product, 1, $intent);
        // Reporting fixture only. The real lifecycle rejects unsupported paid transitions.
        $order->update(['order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        Payment::whereKey($order->payments->sole()->id)->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $report = app(PaymentReceiptReport::class);
        $this->assertSame(0, $report->idrNetTotalCanonical());
        $this->assertSame(['XTR' => 50], $report->netReceiptsByCurrency($report->canonicalPaidPayments()));
        $this->assertSame(['IDR' => 0, 'USDT' => 0, 'XTR' => 50], $report->customerTransactionTotals($buyer->id));
        $presentation = app(PaidInvoiceOrderPresentationAdapter::class)->present($order->fresh());
        $this->assertSame(['Telegram Stars', 'XTR', 'IDR', 50, 50], [
            $presentation->paymentMethod, $presentation->currency, $presentation->itemCurrency,
            $presentation->subtotal(), $presentation->totalPaid,
        ]);
    }

    private function assertNoCheckout(): void
    {
        foreach (['orders', 'payments', 'inventory_movements', 'payment_telegram_stars_details', 'supplier_fulfillments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }
}
