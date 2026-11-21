<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUiIcon;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\Telegram\TelegramOrderIntents;
use App\Services\Telegram\TelegramPaidOrderTxtExporter;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsStarsTransport;
use Tests\Support\TelegramBotTestSupport;

final class TelegramStarsCheckoutUxTest extends TelegramBotTestSupport
{
    use BuildsStarsTransport;

    private int $update = 1000;

    private int $outgoing = 500;

    private bool $rejectPremium = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStarsTransport();
        Queue::fakeExcept([ProcessTelegramUpdate::class]);
        Http::fake(['https://api.telegram.org/*' => function (Request $request) {
            if ($this->rejectPremium && collect($this->buttons($request->data()))->contains(fn ($button) => isset($button['icon_custom_emoji_id']))) {
                return Http::response(['ok' => false, 'description' => 'Bad Request: icon_custom_emoji_id not permitted'], 400);
            }

            return Http::response(['ok' => true, 'result' => [
                'message_id' => $request['message_id'] ?? (preg_match('~/send(Message|Photo)$~', $request->url()) ? ++$this->outgoing : 1),
                'photo' => [['file_id' => 'fixture-banner']],
            ]]);
        }]);
    }

    public function test_paysupport_only_shows_payment_guidance_in_the_buyers_private_chat(): void
    {
        $buyer = $this->buyer();
        config(['services.telegram.admin_contact_url' => 'https://t.me/fixture_support']);
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $this->starsWebhook($this->messageUpdate(8001, '/paysupport@TestStoreBot SECRET-ORDER', ['id' => $buyer->telegram_id]))->assertOk();
        $private = $this->last('sendMessage');
        $this->assertStringContainsString('Telegram Stars payment support', $private['text']);
        $this->assertStringNotContainsString('SECRET-ORDER', $private['text']);
        $this->assertSame('https://t.me/fixture_support', $this->buttons($private)[0]['url']);
        $this->starsWebhook($this->messageUpdate(8003, '/start', [], $buyer->telegram_id))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $this->starsWebhook($this->messageUpdate(8004, '/paysupport', [], $buyer->telegram_id))->assertOk();
        $edit = $this->last('editMessageCaption');
        $this->assertSame($root->message_id, $edit['message_id']);
        $this->assertStringContainsString('Telegram Stars payment support', $edit['caption']);
        $this->starsWebhook($this->messageUpdate(8002, '/paysupport SECRET-ORDER', ['id' => $buyer->telegram_id], -555, 'group'))->assertOk();
        $group = $this->last('sendMessage');
        $this->assertStringNotContainsString('SECRET-ORDER', $group['text']);
        $this->assertStringNotContainsString('Telegram Stars payment support', $group['text']);
        $this->assertSame([], $this->buttons($group));
        $this->assertDatabaseEmpty('orders');
    }

    #[DataProvider('locales')]
    public function test_direct_review_is_localized_and_does_not_create_an_order_without_consent(string $locale, string $pay, string $terms): void
    {
        $buyer = $this->buyer($locale);
        $product = $this->starsProduct(3, withTier: true);
        $cart = $this->starsCart($buyer, $this->starsProduct());
        [$offer, $origin] = $this->openProduct($buyer, $product);
        $this->assertSame('⭐ '.$pay, $offer['text']);
        $this->assertSame('primary', $offer['style']);
        $this->tap($buyer, $offer['callback_data'], $origin + 1);
        $this->assertNotEmpty($this->last('answerCallbackQuery')['text']);
        Http::assertNotSent(fn (Request $request) => $this->button($request->data(), 'stars:confirm:') !== null);
        $this->tap($buyer, $offer['callback_data'], $origin, 'group');
        $this->assertDatabaseEmpty('orders');
        $this->tap($buyer, $offer['callback_data'], $origin);
        $review = $this->last('sendMessage');
        $this->assertStringContainsString($terms, $review['text']);
        $this->assertStringContainsString('50 XTR', $review['text']);
        $this->assertStringNotContainsString('Rp', $review['text']);
        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('inventory_movements');

        $confirm = $this->button($review, 'stars:confirm:');
        $reviewId = $this->outgoing;
        $this->tap($this->buyer($locale, 12346), $confirm['callback_data'], $reviewId);
        $this->tap($buyer, $confirm['callback_data'], $origin);
        $this->assertDatabaseEmpty('orders');
        $this->tap($buyer, $confirm['callback_data'], $reviewId);
        $order = Order::query()->sole();
        $this->assertSame(CartOrigin::TelegramDirect, $order->cart->origin);
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
        $this->assertSame(1, $cart->cartItems()->sole()->quantity);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame('telegram-stars-v1', $order->terms_version);
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertSame('XTR', $order->payments()->sole()->currency);
        $this->assertStringNotContainsString('Deposit', json_encode($this->last('sendMessage'), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        $this->tap($buyer, $confirm['callback_data'], $reviewId);
        $this->assertDatabaseCount('orders', 1);
    }

    public static function locales(): array
    {
        return [
            'id' => ['id', 'Bayar 50 Stars', 'Ketentuan:'],
            'ms' => ['ms', 'Bayar 50 Stars', 'Terma:'],
            'en' => ['en', 'Pay 50 Stars', 'Terms:'],
        ];
    }

    public function test_direct_revision_back_and_gate_off_preserve_navigation_and_other_rails(): void
    {
        $buyer = $this->buyer();
        $product = $this->starsProduct(3, withTier: true);
        $this->starsCart($buyer, $this->starsProduct());
        $this->starsWebhook($this->messageUpdate(++$this->update, '/start', [], $buyer->telegram_id))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $this->tap($buyer, 'product:'.$product->id, $root->message_id);
        $detail = $this->lastWithButton('stars:open:');
        $offer = $this->button($detail, 'stars:open:');
        $this->assertNotNull($this->button($detail, 'detail:pay:qris:'));
        $this->assertNotNull($this->button($detail, 'detail:pay:balance:'));
        $this->tap($buyer, $offer['callback_data'], $root->message_id);
        $review = $this->last('sendMessage');
        $reviewId = $this->outgoing;
        $this->tap($buyer, $this->button($review, 'stars:back:')['callback_data'], $reviewId);
        $this->assertSame($reviewId, (int) $this->last('deleteMessage')['message_id']);
        $this->assertSame($root->message_id, $root->fresh()->message_id);
        $this->tap($buyer, $offer['callback_data'], $root->message_id);
        $confirm = $this->button($this->last('sendMessage'), 'stars:confirm:');
        $reviewId = $this->outgoing;
        $this->tap($buyer, $this->button($detail, 'detail:qty:+:')['callback_data'], $root->message_id);
        $this->tap($buyer, $confirm['callback_data'], $reviewId);
        $this->assertStringContainsString('no longer valid', $this->last('answerCallbackQuery')['text']);
        $this->assertDatabaseEmpty('orders');
        $this->tap($buyer, $this->button($this->lastWithButton('stars:open:'), 'stars:open:')['callback_data'], $root->message_id);
        $review = $this->last('sendMessage');
        $this->assertStringContainsString('× 2 · volume pricing from 2 units', $review['text']);
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false, 'version' => 2]);
        $this->tap($buyer, $this->button($review, 'stars:confirm:')['callback_data'], $this->outgoing);
        $this->assertStringContainsString('unavailable', $this->last('answerCallbackQuery')['text']);
        $this->assertDatabaseEmpty('orders');
        $this->tap($buyer, 'product:'.$product->id, $root->message_id);
        $detail = $this->lastWithButton('detail:pay:qris:');
        $this->assertNull($this->button($detail, 'stars:open:'));
        $this->assertNotNull($this->button($detail, 'detail:pay:balance:'));
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_cart_policy_and_quantity_changes_require_a_fresh_confirm_and_use_same_checkout_attempt(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $buyer = $this->buyer();
        $cart = $this->starsCart($buyer, $this->starsProduct(3, withTier: true));
        $this->tap($buyer, 'checkout', 400);
        $method = $this->last('sendMessage');
        $methodId = $this->outgoing;
        $open = $this->button($method, 'stars:open:')['callback_data'];
        $this->assertNotNull($this->button($method, 'checkout:current'));
        $this->assertNotNull($this->button($method, 'checkout:balance'));
        $this->tap($buyer, $open, $methodId - 1);
        $this->assertStringContainsString('no longer valid', $this->last('answerCallbackQuery')['text']);
        $this->tap($buyer, $open, $methodId);
        $confirm = $this->button($this->last('sendMessage'), 'stars:confirm:')['callback_data'];
        $first = $this->outgoing;
        $cart->cartItems()->update(['quantity' => 2]);
        DB::table('stars_pricing_policies')->update(['idr_per_usd' => 18_500, 'version' => 2]);
        $this->tap($buyer, $confirm, $first);
        $new = $this->lastWithButton('stars:confirm:');
        $newId = $new['message_id'];
        $this->assertStringContainsString('confirm again', $new['text']);
        $this->assertStringContainsString('49 XTR', $new['text']);
        $this->assertStringContainsString('× 2 · volume pricing from 2 units', $new['text']);
        $this->tap($buyer, $confirm, $first);
        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('inventory_movements');
        $this->tap($buyer, $this->button($new, 'stars:confirm:')['callback_data'], $newId);
        $this->assertSame(CartStatus::CheckedOut, $cart->fresh()->status);
        $this->tap($buyer, 'checkout:current', $methodId);
        $order = Order::query()->sole();
        $this->assertSame($cart->id, $order->cart_id);
        $this->assertSame(49, $order->payments()->sole()->amount);
    }

    public function test_invoice_recovery_uses_only_the_original_ack_and_paid_copy_follows_settlement(): void
    {
        $order = $this->starsOrder();
        $buyer = $order->customer;
        $buyer->update(['locale' => 'en']);
        $payment = $order->payments->sole();
        $target = $payment->telegramStarsDetail;
        $detail = 'order:'.$order->invoice_number;
        $label = 'Telegram Stars · '.$payment->amount.' XTR';
        $this->tap($buyer, $detail, 400);
        $this->assertStringContainsString('being prepared', $this->last('sendMessage')['text']);
        TelegramOutboundEffect::query()->sole()->update(['state' => TelegramOutboundEffectState::Unknown]);
        $this->tap($buyer, $detail, 400);
        $this->assertStringContainsString('Do not pay again', $this->last('sendMessage')['text']);
        $this->assertArrayNotHasKey('reply_parameters', $this->last('sendMessage'));

        $this->acknowledgeStarsInvoiceFixture();
        $this->tap($buyer, $detail, 400);
        $known = $this->last('sendMessage');
        $this->assertSame(['message_id' => 701, 'allow_sending_without_reply' => true], $known['reply_parameters']);
        $this->assertStringContainsString('Settings → My Stars', $known['text']);
        $this->assertStringContainsString($label, $known['text']);
        $this->assertStringNotContainsString('Rp', $known['text']);
        $this->assertStringContainsString('Payment: Telegram Stars (Awaiting payment)', $known['text']);
        $this->assertStringNotContainsString('Paid:', $known['text']);
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $this->tap($buyer, $detail, 400);
        $this->assertStringContainsString('payments are paused', $this->last('sendMessage')['text']);
        $this->assertArrayNotHasKey('reply_parameters', $this->last('sendMessage'));
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => true]);
        $this->starsWebhook($this->starsQuery($target))->assertJsonPath('ok', true);
        $this->tap($buyer, $detail, 400);
        $this->assertStringContainsString('being verified', $this->last('sendMessage')['text']);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false]);
        $this->starsWebhook($this->starsSuccess($target, 202))->assertOk();
        $this->artisan('telegram:recover-stars-payments')->assertSuccessful();
        $recorded = Http::recorded()->count();
        $this->tap($buyer, $detail, 400);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $paidCopy = Http::recorded()->slice($recorded)->map(fn ($pair) => $pair[0]['text'] ?? '')->implode("\n");
        $this->assertStringContainsString('Paid:', $paidCopy);
        $this->assertArrayNotHasKey('reply_parameters', $this->last('sendMessage'));
        $history = app(TelegramOrderIntents::class)->orderHistorySurface($buyer);
        $this->assertStringContainsString($label, $this->button(['reply_markup' => $history->keyboard], $detail)['text']);
        $receipt = app(TelegramReceiptNotifier::class)->render($order->fresh());
        $receiptText = implode("\n", $receipt['chunks']);
        $this->assertStringContainsString('Telegram Stars', $receiptText);
        $this->assertStringContainsString($payment->amount.' XTR', $receiptText);
        $this->assertStringNotContainsString('Rp', $receiptText);
        $image = app(PaidInvoiceOrderPresentationAdapter::class)->present($order->fresh());
        $this->assertSame('XTR', $image->currency);
        $this->assertSame($payment->amount, $image->totalPaid);
        $this->assertNull($image->items[0]['unit_price']);
        $this->assertNull($image->items[0]['subtotal']);
        $txt = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $buyer);
        $this->assertStringContainsString($label, $txt->documentContents());
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/sendInvoice'));
    }

    public function test_expired_order_and_notice_show_xtr_without_an_invoice_reply(): void
    {
        $order = $this->starsOrder();
        $order->customer->update(['locale' => 'en']);
        $this->acknowledgeStarsInvoiceFixture();
        $this->travelTo($order->expires_at->copy()->addSecond());
        $this->tap($order->customer, 'order:'.$order->invoice_number, 400);
        $this->assertStringContainsString('has expired', $this->last('sendMessage')['text']);
        $this->assertArrayNotHasKey('reply_parameters', $this->last('sendMessage'));
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $notice = $this->last('sendMessage');
        $this->assertStringContainsString('Telegram Stars · 50 XTR', $notice['text']);
        $this->assertStringNotContainsString('Rp', $notice['text']);
        $this->assertNotNull($order->fresh()->telegram_expired_order_notified_at);
        $count = Http::recorded()->count();
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertCount($count, Http::recorded());
    }

    public function test_expired_offer_and_consent_refresh_without_creating_an_order(): void
    {
        $buyer = $this->buyer();
        [$offer, $origin] = $this->openProduct($buyer, $this->starsProduct());
        $this->travel(6)->minutes();
        $this->tap($buyer, $offer['callback_data'], $origin);
        $first = $this->last('sendMessage');
        $firstId = $this->outgoing;
        $this->assertStringContainsString('quote expired', $first['text']);
        $confirm = $this->button($first, 'stars:confirm:')['callback_data'];

        $this->travel(6)->minutes();
        $this->tap($buyer, $confirm, $firstId);
        $refresh = $this->last('editMessageText');
        $this->assertStringContainsString('quote expired', $refresh['text']);
        $newConfirm = $this->button($refresh, 'stars:confirm:')['callback_data'];
        $this->assertNotSame($confirm, $newConfirm);
        $this->tap($buyer, $confirm, $firstId);
        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('inventory_movements');
        $this->tap($buyer, $newConfirm, $firstId);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_phased_cancellation_is_visible_before_approval_and_held_during_verification(): void
    {
        $order = $this->starsOrder();
        $buyer = $order->customer;
        $detail = 'order:'.$order->invoice_number;
        $this->acknowledgeStarsInvoiceFixture();
        $this->tap($buyer, $detail, 400);
        $cancel = $this->button($this->last('sendMessage'), 'buyer:cancel-pending:')['callback_data'];
        $this->starsWebhook($this->starsQuery($order->payments->sole()->telegramStarsDetail))->assertJsonPath('ok', true);
        $this->tap($buyer, $detail, 400);
        $this->assertNull($this->button($this->last('sendMessage'), 'buyer:cancel-pending:'));
        $this->tap($buyer, $cancel, 400);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        $unpaid = $this->starsOrder('unpaid-cancel');
        $this->tap($unpaid->customer, 'order:'.$unpaid->invoice_number, 400);
        $cancel = $this->button($this->last('sendMessage'), 'buyer:cancel-pending:')['callback_data'];
        $this->tap($unpaid->customer, $cancel, 400);
        $this->assertSame(OrderStatus::Cancelled, $unpaid->fresh()->order_status);
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $unpaid->id, 'type' => 'release']);
    }

    public function test_ineligible_cart_and_product_do_not_offer_stars_or_hide_qris(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $buyer = $this->buyer();
        $numeric = Product::factory()->create(['stock' => 5]);
        $this->tap($buyer, 'product:'.$numeric->id, 400);
        $detail = $this->lastWithButton('detail:pay:qris:');
        $this->assertNull($this->button($detail, 'stars:open:'));

        $cart = $this->starsCart($buyer, $this->starsProduct());
        $cart->cartItems()->create(['product_id' => $numeric->id, 'quantity' => 1]);
        $this->tap($buyer, 'checkout', 400);
        $method = $this->last('sendMessage');
        $this->assertNotNull($this->button($method, 'checkout:current'));
        $this->assertNull($this->button($method, 'stars:open:'));
    }

    public function test_icon_capture_supports_the_stars_slot_and_rejected_premium_icon_preserves_the_fallback(): void
    {
        config(['services.telegram.admin_telegram_chat_id' => '999']);
        $admin = $this->buyer('en', 999);
        $icon = TelegramUiIcon::where('key', 'payment.stars')->sole();
        $this->tap($admin, 'menuicon:list', 400);
        $this->assertNotNull($this->button($this->lastWithButton('menuicon:pick:'.$icon->id), 'menuicon:pick:'.$icon->id));
        $this->tap($admin, 'menuicon:pick:'.$icon->id, 400);
        $this->starsWebhook($this->messageUpdate(++$this->update, '⭐', [], 999))->assertOk();
        $this->assertNull($icon->fresh()->custom_emoji_id);
        $this->assertSame('⭐', $icon->fresh()->fallback_emoji);
        $icon->update(['custom_emoji_id' => '5368324170671202286']);
        $buyer = $this->buyer();
        $product = $this->starsProduct();
        [$premium] = $this->openProduct($buyer, $product);
        $this->assertSame('5368324170671202286', $premium['icon_custom_emoji_id']);
        $this->assertArrayNotHasKey('_icon_fallback', $premium);
        $this->rejectPremium = true;
        [$fallback] = $this->openProduct($buyer, $product);
        $this->assertSame('⭐ Pay 50 Stars', $fallback['text']);
        $this->assertSame('primary', $fallback['style']);
        $this->assertArrayNotHasKey('icon_custom_emoji_id', $fallback);
    }

    private function buyer(string $locale = 'en', int $telegramId = 12345): Customer
    {
        return Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => $locale]);
    }

    private function openProduct(Customer $buyer, Product $product): array
    {
        $this->tap($buyer, 'product:'.$product->id, 400);
        $request = $this->lastWithButton('stars:open:');

        return [$this->button($request, 'stars:open:'), $request['message_id'] ?? $this->outgoing];
    }

    private function tap(Customer $buyer, string $callback, int $messageId, string $chatType = 'private'): void
    {
        $id = ++$this->update;
        $this->starsWebhook($this->callbackUpdate($id, 'tap-'.$id, $callback, ['id' => $buyer->telegram_id], $buyer->telegram_id, $chatType, $messageId))->assertOk();
    }

    private function button(array $payload, string $prefix): ?array
    {
        return collect($this->buttons($payload))->first(fn ($button) => str_starts_with($button['callback_data'] ?? '', $prefix));
    }

    private function buttons(array $payload): array
    {
        $markup = $payload['reply_markup'] ?? [];
        $markup = is_string($markup) ? json_decode($markup, true, flags: JSON_THROW_ON_ERROR) : $markup;

        return array_merge(...($markup['inline_keyboard'] ?? []));
    }

    private function last(string $method): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/'.$method))->last()[0]->data();
    }

    private function lastWithButton(string $prefix): array
    {
        return Http::recorded(fn (Request $request) => $this->button($request->data(), $prefix) !== null)->last()[0]->data();
    }
}
