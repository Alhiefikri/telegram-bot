<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Jobs\SendTelegramReceipt;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierInvoiceRetry;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Telegram\AdminOrderNotificationDeliveryPipeline;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

class SupplierInvoiceRetryTest extends TestCase
{
    public function test_paid_unlimited_prefers_supplier_without_using_local_stock(): void
    {
        [$order, , $product] = $this->checkoutInvoice(unlimited: true);
        Http::assertNothingSent();
        $this->assertSame(0, SupplierFulfillment::count());
        $lifecycle = app(OrderLifecycle::class);
        $lifecycle->applyManualCompletion($order);
        $lifecycle->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::sole();
        $this->assertSame('pending', $fulfillment->state);
        $this->assertSame(0, $fulfillment->orderItem->localStockQuantity());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, ManualFulfillmentReview::count());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->deliverFixture($fulfillment);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('retry-account', $fulfillment->orderItem->stockItems()->sole()->secret);
        Queue::assertPushed(SendTelegramReceipt::class);

        config(['services.telegram.token' => 'test-token']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7001]])]);
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->assertNotNull($receipt);
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        while ($receipt !== null) {
            app(ReceiptDeliveryPipeline::class)->deliver($receipt, dispatchNext: false);
            $receipt = $ledger->nextReceiptEffect($receipt->effect_group_key, $receipt->sequence);
        }
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertTrue($order->telegramOutboundEffects()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->where('telegram_chat_id', (string) $order->customer->telegram_id)
            ->where('outcome_metadata->contains_credentials', true)->where('state', 'sent')->exists());
    }

    public function test_unlimited_with_empty_supplier_wallet_can_be_retried_from_review_after_topup(): void
    {
        config(['services.telegram.token' => 'test-token', 'services.telegram.admin_telegram_chat_id' => '999123', 'app.url' => 'https://lhiestore.web.id']);
        [$order, , , $sku] = $this->checkoutInvoice(unlimited: true, balance: 0);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $this->assertSame(0, SupplierFulfillment::count());
        $review = ManualFulfillmentReview::sole();
        $this->assertSame(ManualFulfillmentReview::REASON_MANUAL_UNLIMITED, $review->reason);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $show = route('admin.manual-fulfillment-reviews.show', $review);
        $url = route('admin.manual-fulfillment-reviews.retry-supplier', $review);
        $data = ['item_id' => $order->orderItems()->sole()->id];
        $this->actingAs($owner)->get($show)->assertOk()
            ->assertSee('Coba beli dari supplier')->assertSee('Lengkapi akun lokal');
        $this->post($url, $data)->assertRedirect(route('password.confirm'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->withSession(['auth.password_confirmed_at' => time()])->post($url, $data)->assertForbidden();
        Http::assertNothingSent();

        $effectTypes = [
            TelegramOutboundEffectType::AdminOrderPaidNotification->value,
            TelegramOutboundEffectType::AdminOrderReviewNotification->value,
        ];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 120]])]);
        foreach (TelegramOutboundEffect::whereIn('effect_type', $effectTypes)->get() as $effect) {
            app(AdminOrderNotificationDeliveryPipeline::class)->deliver($effect);
        }
        Http::assertSentCount(1);
        $buttons = collect(Http::recorded()->sole()[0]['reply_markup']['inline_keyboard'])->collapse()->keyBy('text');
        $this->assertSame('https://lhiestore.web.id'.route('admin.manual-fulfillment-reviews.show', $review, absolute: false), $buttons['🔎 Monitor pembelian']['url']);
        $this->assertSame(route('admin.orders.show', $order), $buttons['🧾 Buka detail invoice']['url']);

        $this->fakeSnapshot($sku, balance: '0');
        $this->actingAs($owner)->post($url, $data)->assertRedirect($show)->assertSessionHasErrors('supplier');
        $this->assertSame(0, SupplierFulfillment::count());
        $this->get($show)->assertSee('Lengkapi akun lokal');
        $this->fakeSnapshot($sku);
        $this->post($url, $data)->assertRedirect($show)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertRedirect($show)->assertSessionHasErrors('supplier');
        $this->assertSame(0, SupplierPurchase::count());
        Http::assertSentCount(2);
        $this->deliverFixture(SupplierFulfillment::sole());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(ManualFulfillmentReview::STATE_FULFILLED, $review->fresh()->state);
        Queue::assertPushed(SendTelegramReceipt::class, fn (SendTelegramReceipt $job) => $job->orderId === $order->id);
    }

    public static function unusableMappings(): array
    {
        return [
            'wallet unknown' => [[], ['balance_micros' => null]],
            'supplier disabled' => [[], ['is_active' => false]],
            'stock exhausted' => [['stock' => 0], []],
            'requires buyer input' => [['requires_input' => true], []],
            'above retail ceiling' => [['unit_price_micros' => 5_000_000], []],
        ];
    }

    #[DataProvider('unusableMappings')]
    public function test_unlimited_unusable_supplier_does_not_block_payment_or_manual_delivery(array $skuChanges, array $supplierChanges): void
    {
        [$order, $supplier, , $sku] = $this->checkoutInvoice(unlimited: true);
        $sku->forceFill($skuChanges)->save();
        $supplier->forceFill($supplierChanges)->save();
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::assertNothingSent();
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, SupplierFulfillment::count());
        $review = ManualFulfillmentReview::sole();
        $item = $order->orderItems()->sole();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->post(route('admin.manual-fulfillment-reviews.credentials', $review), [
                'items' => [$item->id => ['format' => 'legacy', 'credentials' => 'fixture-manual']],
            ])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('fixture-manual', $item->stockItems()->sole()->secret);
        $this->assertSame(0, SupplierPurchase::count());
        Http::assertNothingSent();
    }

    public function test_unlimited_timeout_blocks_supplier_rebuy_but_accepts_manual_accounts_in_review(): void
    {
        [$order, $supplier] = $this->checkoutInvoice(ambiguous: true, unlimited: true);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::sole();
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('Timed out after dispatch.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::sole();
        $purchase = SupplierPurchase::sole();
        $this->assertSame('ambiguous', $purchase->state);
        $url = route('admin.manual-fulfillment-reviews.supplier', [$review, $fulfillment]);
        $show = route('admin.manual-fulfillment-reviews.show', $review);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, ['action' => 'retry', 'purchase_id' => $purchase->id])->assertRedirect($show)
            ->assertSessionHas('status', 'Pembelian ulang belum aman atau invoice tidak lagi memenuhi syarat. Periksa transaksi supplier.');
        $this->get($show)->assertSee('Simpan dan kirim akun manual')->assertDontSee('Coba beli dari supplier');
        $this->post($url, ['action' => 'send_accounts', 'purchase_id' => $purchase->id, 'confirmed' => 1, 'credentials' => 'manual-account'])
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertSame('manual-account', $fulfillment->orderItem->stockItems()->sole()->secret);
        $this->assertSame('ambiguous', $purchase->fresh()->state);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(ManualFulfillmentReview::STATE_FULFILLED, $review->fresh()->state);
        Queue::assertPushed(SendTelegramReceipt::class);
    }

    public function test_unlimited_manual_fallback_releases_an_unused_supplier_hold(): void
    {
        [$order, $supplier, , $sku] = $this->checkoutInvoice(unlimited: true);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::sole();
        $sku->update(['orderable' => false]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::sole();
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, SupplierPurchase::count());
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.supplier', [$review, $fulfillment]),
                ['action' => 'send_accounts', 'purchase_id' => 0, 'confirmed' => 1, 'credentials' => 'manual-instead'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        Http::assertNothingSent();
    }

    public function test_unlimited_supplier_review_can_retry_a_proven_rejection(): void
    {
        [$order, $supplier, , $sku] = $this->checkoutInvoice(unlimited: true);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::sole();
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'Insufficient balance'], 402));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::sole();
        $old = SupplierPurchase::sole();
        $this->assertSame('rejected', $old->state);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->fakeSnapshot($sku);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->get(route('admin.manual-fulfillment-reviews.show', $review))
            ->assertOk()->assertSee('Coba beli dari supplier')->assertSee('Simpan dan kirim akun manual');
        $url = route('admin.manual-fulfillment-reviews.supplier', [$review, $fulfillment]);
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, ['action' => 'retry', 'purchase_id' => 0])
            ->assertSessionHas('status', 'Pembelian ulang belum aman atau invoice tidak lagi memenuhi syarat. Periksa transaksi supplier.');
        Http::assertNothingSent();
        $this->assertSame(1, SupplierPurchase::count());
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, ['action' => 'retry', 'purchase_id' => $old->id])
            ->assertRedirect(route('admin.manual-fulfillment-reviews.show', $review))->assertSessionHasNoErrors();
        $this->assertNotSame($old->id, $fulfillment->fresh()->current_purchase_id);
        $this->deliverFixture($fulfillment);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(ManualFulfillmentReview::STATE_FULFILLED, $review->fresh()->state);
    }

    private function deliverFixture(SupplierFulfillment $fulfillment): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'retry-order', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['retry-account']]));
        $service = app(SupplierFulfillmentService::class);
        $service->process($fulfillment->id);
        $service->process($fulfillment->id);
        Http::assertSentCount(1);
    }

    public function test_owner_dashboard_retry_synchronizes_and_queues_only_one_purchase(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $old = $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $sku->update(['unit_price_micros' => 1_000_000]);
        $this->fakeSnapshot($sku);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $url = route('admin.suppliers.resolve-invoice', $fulfillment);
        $payload = ['action' => 'retry', 'purchase_id' => $old->id];
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, $payload)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pembelian dijadwalkan. Jangan menekan ulang; periksa hasil pembelian dan pengiriman.');
        $this->post($url, $payload)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pembelian sedang diproses. Tidak ada pembelian baru yang dijadwalkan.');
        $this->assertSame(2, SupplierPurchase::count());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_retry_is_rejected_if_the_purchase_changes_during_supplier_sync(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $original = $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        Http::swap(new Factory);
        Http::fake(function ($request) use ($fulfillment, $supplier, $sku) {
            if (str_ends_with($request->url(), '/balance')) {
                $this->attachRejectedPurchase($fulfillment, $supplier, $sku);

                return Http::response(['balance' => '10', 'currency' => 'USDT']);
            }

            return Http::response(['currency' => 'USDT', 'products' => [[
                'id' => $sku->external_id, 'name' => 'Retry SKU', 'price' => '1', 'stock' => 10,
            ]]]);
        });
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.resolve-invoice', $fulfillment), ['action' => 'retry', 'purchase_id' => $original->id])
            ->assertSessionHas('status', 'Pembelian ulang belum aman atau invoice tidak lagi memenuhi syarat. Periksa transaksi supplier.');
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame(2, SupplierPurchase::count());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_unlimited_retry_stops_when_refund_becomes_uncertain_during_sync(): void
    {
        [$order, , , $sku] = $this->checkoutInvoice(unlimited: true);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::sole();
        $sku->update(['orderable' => false]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        Http::swap(new Factory);
        Http::fake(function ($request) use ($review, $owner, $sku) {
            if (str_ends_with($request->url(), '/balance')) {
                app(ManualFulfillmentResolutionService::class)
                    ->resolve($review, $owner, 'refund', 'Provider status unknown', 'fixture-refund', 'uncertain');

                return Http::response(['balance' => '10', 'currency' => 'USDT']);
            }

            return Http::response(['currency' => 'USDT', 'products' => [[
                'id' => $sku->external_id, 'name' => 'Retry SKU', 'price' => '1', 'stock' => 10,
            ]]]);
        });
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.supplier', [$review, $fulfillment]), ['action' => 'retry', 'purchase_id' => 0])
            ->assertSessionHas('status', 'Pembelian ulang belum aman atau invoice tidak lagi memenuhi syarat. Periksa transaksi supplier.');
        $this->assertSame(ManualFulfillmentReview::STATE_REFUND_UNCERTAIN, $review->fresh()->state);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame(0, SupplierPurchase::count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_unlimited_worker_does_not_buy_after_reserved_review_enters_refund_uncertain(): void
    {
        [$order, , , $sku] = $this->checkoutInvoice(unlimited: true, balance: 0);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $review = ManualFulfillmentReview::sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->fakeSnapshot($sku);
        $service = app(SupplierFulfillmentService::class);
        $this->assertSame('queued', $service->retryUnlimitedReview($review, $order->orderItems()->sole()->id, $owner));
        app(ManualFulfillmentResolutionService::class)
            ->resolve($review, $owner, 'refund', 'Provider status unknown', 'fixture-refund', 'uncertain');
        $service->process(SupplierFulfillment::sole()->id);
        $this->assertSame(0, SupplierPurchase::count());
        $this->assertSame(['processed' => 0], $service->recover());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_mixed_unlimited_order_waits_for_local_credentials_before_receipt(): void
    {
        [$order] = $this->checkoutInvoice(unlimited: true, includeMissingLocal: true);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $review = ManualFulfillmentReview::sole();
        $this->assertSame(ManualFulfillmentReview::REASON_MIXED_LOCAL_CREDENTIALS_MISSING, $review->reason);
        $this->deliverFixture(SupplierFulfillment::sole());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        $local = $order->orderItems()->where('manual_unlimited', false)->sole();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->post(route('admin.manual-fulfillment-reviews.credentials', $review), [
                'items' => [$local->id => ['format' => 'legacy', 'credentials' => 'missing-local-account']],
            ])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('missing-local-account', $local->stockItems()->sole()->secret);
        Queue::assertPushed(SendTelegramReceipt::class);
    }

    public function test_unlimited_review_replaces_paid_telegram_card_sent_before_review_existed(): void
    {
        config(['services.telegram.token' => 'test-token', 'services.telegram.admin_telegram_chat_id' => '999123', 'app.url' => 'https://lhiestore.web.id']);
        [$order] = $this->checkoutInvoice(unlimited: true);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $this->travel(121)->seconds();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $messages = 0;
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function () use (&$messages) {
                return Http::response(['ok' => true, 'result' => ['message_id' => ++$messages]]);
            },
            'https://api.telegram.org/*/editMessageText' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'https://prodseller.com/*' => Http::response(['error' => 'Insufficient balance'], 402),
        ]);
        $pipeline = app(AdminOrderNotificationDeliveryPipeline::class);
        $paid = TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::AdminOrderPaidNotification->value)->sole();
        $pipeline->deliver($paid);
        $this->assertNull($paid->fresh()->outcome_metadata['covered_review_id']);
        app(SupplierFulfillmentService::class)->process(SupplierFulfillment::sole()->id);
        $review = ManualFulfillmentReview::sole();
        $notice = TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)->sole();
        $pipeline->deliver($notice);
        $pipeline->deliver($notice);
        $this->assertSame(1, $messages);
        $link = 'https://lhiestore.web.id'.route('admin.manual-fulfillment-reviews.show', $review, absolute: false);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/editMessageText') && (int) $request['message_id'] === 1
            && in_array($link, collect($request['reply_markup']['inline_keyboard'])->collapse()->pluck('url')->all(), true));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage'));
    }

    public function test_unlimited_review_delivers_when_paid_telegram_card_has_terminal_failure(): void
    {
        config(['services.telegram.token' => 'test-token', 'services.telegram.admin_telegram_chat_id' => '999123']);
        [$order] = $this->checkoutInvoice(unlimited: true, balance: 0);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $paid = TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::AdminOrderPaidNotification->value)->sole();
        $claimed = app(TelegramOutboundEffectLedgerProtocol::class)->claim($paid->id);
        app(TelegramOutboundEffectLedgerProtocol::class)->finalizeNotDeliverable($claimed->id, $claimed->claim_token, 'admin_notification_not_eligible');
        $notice = TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)->sole();
        Http::swap(new Factory);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        app(AdminOrderNotificationDeliveryPipeline::class)->deliver($notice);
        $this->assertSame(TelegramOutboundEffectState::Sent, $notice->fresh()->state);
        Http::assertSentCount(1);
    }

    public function test_paid_notice_links_to_fulfillment_review_not_newer_alternate_payment_review(): void
    {
        config(['services.telegram.token' => 'test-token', 'services.telegram.admin_telegram_chat_id' => '999123', 'app.url' => 'https://lhiestore.web.id']);
        [$order] = $this->checkoutInvoice(unlimited: true, balance: 0);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $review = ManualFulfillmentReview::sole();
        $alternate = Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid,
            'paid_at' => now(), 'manual_alternative_at' => now()]);
        $other = ManualFulfillmentReview::create([
            'order_id' => $order->id, 'payment_id' => $alternate->id, 'amount' => $alternate->amount, 'currency' => 'IDR',
            'state' => ManualFulfillmentReview::STATE_PENDING, 'reason' => ManualFulfillmentReview::REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE,
        ]);
        Http::swap(new Factory);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        $paid = TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::AdminOrderPaidNotification->value)->sole();
        app(AdminOrderNotificationDeliveryPipeline::class)->deliver($paid);
        $urls = collect(Http::recorded()->sole()[0]['reply_markup']['inline_keyboard'])->collapse()->pluck('url');
        $this->assertContains('https://lhiestore.web.id'.route('admin.manual-fulfillment-reviews.show', $review, absolute: false), $urls);
        $this->assertNotContains('https://lhiestore.web.id'.route('admin.manual-fulfillment-reviews.show', $other, absolute: false), $urls);
    }

    public function test_unlimited_retry_stops_after_first_eligible_supplier_without_syncing_backups(): void
    {
        [$order, $supplier, $product, $sku] = $this->checkoutInvoice(unlimited: true, balance: 0);
        $backup = $supplier->replicate();
        $backup->forceFill(['provider' => 'aivault', 'currency' => 'USD'])->save();
        $backupSku = $sku->replicate();
        $backupSku->supplier_id = $backup->id;
        $backupSku->save();
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $backupSku->id, 'priority' => 2, 'is_active' => true]);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $this->fakeSnapshot($sku);
        $service = app(SupplierFulfillmentService::class);
        $this->assertSame('queued', $service->retryUnlimitedReview(ManualFulfillmentReview::sole(), $order->orderItems()->sole()->id,
            User::factory()->create(['role' => UserRole::Owner])));
        $this->assertSame($supplier->id, SupplierFulfillment::sole()->reserved_supplier_id);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'aivaulthub.store'));
    }

    public function test_dashboard_requires_owner_confirmation_for_the_exact_above_limit_quote(): void
    {
        [$fulfillment, , $sku] = $this->reviewedInvoice();
        $this->fakeSnapshot($sku);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $url = route('admin.suppliers.resolve-invoice', $fulfillment);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, ['action' => 'retry', 'purchase_id' => 0])->assertSessionHasNoErrors();
        $quote = session('supplier_retry_preview');
        $this->assertSame(5000, $quote['cost_idr']);
        $this->post($url, ['action' => 'approve_price', 'purchase_id' => 0, 'approval_token' => $quote['token']])
            ->assertSessionHasErrors('recovery');
        $this->post($url, ['action' => 'approve_price', 'purchase_id' => 0, 'approval_token' => $quote['token'], 'confirmed' => 1])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pembelian dijadwalkan. Jangan menekan ulang; periksa hasil pembelian dan pengiriman.');
        $this->assertSame(1, SupplierPurchase::count());
    }

    public function test_price_approval_is_one_use_and_revalidated_before_dispatch(): void
    {
        [$fulfillment, , $sku] = $this->reviewedInvoice();
        $retry = app(SupplierInvoiceRetry::class);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->fakeSnapshot($sku);
        $this->assertSame('price_approval_required', $retry->attempt($fulfillment->id, false, 5000)['status']);
        $this->assertSame(0, SupplierPurchase::count());

        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('price_approval_required', $preview['status']);
        $sku->update(['unit_price_micros' => 6_000_000]);
        $this->assertSame('quote_changed', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        $this->assertSame(0, SupplierPurchase::count());

        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('queued', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        $this->assertSame('already_processing', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        $sku->update(['unit_price_micros' => 7_000_000]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame('rejected', SupplierPurchase::sole()->state);
        $this->assertNull(SupplierPurchase::sole()->dispatched_at);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_proven_rejected_hold_is_released_even_when_fresh_stock_is_zero(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $sku->update(['stock' => 0]);
        $this->fakeSnapshot($sku);
        $this->assertSame('stock_unavailable', app(SupplierInvoiceRetry::class)->attempt($fulfillment->id)['status']);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame('review', $fulfillment->fresh()->state);
    }

    public function test_rejected_purchase_retries_only_once_and_uses_a_new_purchase(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $old = $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $sku->update(['unit_price_micros' => 1_000_000]);
        $this->fakeSnapshot($sku);
        $retry = app(SupplierInvoiceRetry::class);
        $this->assertSame('queued', $retry->attempt($fulfillment->id)['status']);
        $this->assertSame('already_processing', $retry->attempt($fulfillment->id)['status']);
        $this->assertSame(2, SupplierPurchase::count());
        $this->assertNotSame($old->id, $fulfillment->fresh()->current_purchase_id);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'retry-order', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['retry-account']]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertSame(2, SupplierPurchase::count());
        Http::assertSentCount(1);
    }

    public function test_rejected_purchase_with_contradictory_debit_evidence_never_releases_or_retries(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $purchase = $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $purchase->forceFill(['actual_cost_micros' => 1_000_000])->save();
        $retry = app(SupplierInvoiceRetry::class);

        $this->assertSame('unsafe', $retry->attempt($fulfillment->id)['status']);
        $this->assertSame('unsafe', $retry->releaseProvenRejected($fulfillment->id)['status']);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, SupplierPurchase::count());
        Http::assertNothingSent();
    }

    public function test_stale_dashboard_takeover_keeps_ambiguous_supplier_hold(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice(ambiguous: true);
        $purchase = SupplierPurchase::sole();
        Http::swap(new Factory);
        Http::fake(fn () => Http::response([], 503));
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.resolve-invoice', $fulfillment), [
                'action' => 'take_over', 'purchase_id' => $purchase->id, 'confirmed' => 1,
            ])->assertSessionHasNoErrors();
        $this->assertNotNull($fulfillment->fresh()->manual_takeover_at);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame('ambiguous', $purchase->fresh()->state);
        $this->assertSame('manual_takeover', app(SupplierInvoiceRetry::class)->attempt($fulfillment->id)['status']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_ambiguous_purchase_never_retries_or_releases_its_hold(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice(ambiguous: true);
        $purchase = SupplierPurchase::sole();
        $this->assertSame('ambiguous', $purchase->state);
        $this->assertSame('unsafe', app(SupplierInvoiceRetry::class)->attempt($fulfillment->id)['status']);
        $this->assertSame('unsafe', app(SupplierInvoiceRetry::class)->releaseProvenRejected($fulfillment->id)['status']);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame($purchase->id, $fulfillment->fresh()->current_purchase_id);
        $this->assertSame(1, SupplierPurchase::count());
        Http::assertSentCount(1);
    }

    public function test_approved_exact_quote_can_complete_without_changing_the_original_ceiling(): void
    {
        [$fulfillment] = $this->reviewedInvoice();
        $originalCeiling = $fulfillment->maximum_cost_idr;
        $retry = app(SupplierInvoiceRetry::class);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('price_approval_required', $preview['status']);
        $this->assertSame('queued', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'approved-order', 'quantity' => 1, 'total_cost' => '5', 'deliveredKeys' => ['approved-account']]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertSame($originalCeiling, $fulfillment->fresh()->maximum_cost_idr);
        Http::assertSentCount(1);
    }

    public function test_supplier_debit_above_the_approved_quote_is_not_delivered(): void
    {
        [$fulfillment] = $this->reviewedInvoice();
        $retry = app(SupplierInvoiceRetry::class);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('queued', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'over-quote-order', 'quantity' => 1, 'total_cost' => '6', 'deliveredKeys' => ['over-quote-account']]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame('cost_mismatch', $fulfillment->fresh()->last_error);
        $this->assertSame(0, $fulfillment->orderItem->stockItems()->count());
        Http::assertSentCount(1);
    }

    private function fakeSnapshot(SupplierCatalogItem $sku, string $balance = '10'): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) use ($sku, $balance) {
            $current = $sku->fresh();

            return Http::response(str_ends_with($request->url(), '/balance')
                ? ['balance' => $balance, 'currency' => 'USDT']
                : ['currency' => 'USDT', 'products' => [[
                    'id' => $current->external_id, 'name' => 'Retry SKU',
                    'price' => SupplierMoney::decimal($current->unit_price_micros), 'stock' => $current->stock,
                ]]]);
        });
    }

    private function attachRejectedPurchase(SupplierFulfillment $fulfillment, Supplier $supplier, SupplierCatalogItem $sku): SupplierPurchase
    {
        $purchase = SupplierPurchase::create([
            'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $sku->id, 'reference' => (string) Str::uuid(),
            'quantity' => 1, 'expected_cost_micros' => 1_000_000, 'exchange_rate_idr' => 1000,
            'state' => 'rejected', 'dispatched_at' => now(),
        ]);
        $fulfillment->update(['current_purchase_id' => $purchase->id]);

        return $purchase;
    }

    private function reviewedInvoice(bool $ambiguous = false): array
    {
        [$order, $supplier, , $sku] = $this->checkoutInvoice($ambiguous);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        if ($ambiguous) {
            Http::fake(fn () => Http::response([], 500));
        } else {
            $sku->update(['unit_price_micros' => 5_000_000]);
        }
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('review', $fulfillment->fresh()->state);

        return [$fulfillment, $supplier, $sku];
    }

    private function checkoutInvoice(bool $ambiguous = false, bool $unlimited = false, int $balance = 10_000_000, bool $includeMissingLocal = false): array
    {
        Queue::fake();
        Http::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000, 'manual_unlimited' => $unlimited]);
        $supplier = Supplier::forceCreate([
            'name' => 'Retry supplier', 'provider' => $ambiguous ? 'warzone' : 'prodseller', 'api_key' => 'test-secret',
            'currency' => $ambiguous ? 'USD' : 'USDT',
            'currency_confirmed' => true, 'exchange_rate_idr' => 1000, 'balance_micros' => $balance,
            'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
        $sku = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => 'retry-sku', 'name' => 'Retry SKU',
            'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false,
            'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customer = Customer::factory()->create(['telegram_id' => 790001]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        if ($includeMissingLocal) {
            $local = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'manual_unlimited' => false]);
            CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $local->id, 'quantity' => 1]);
        }
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());

        return [$order, $supplier, $product, $sku];
    }
}
