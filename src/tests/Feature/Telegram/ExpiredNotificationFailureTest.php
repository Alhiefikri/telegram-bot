<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Telegram\TelegramOrderExpiredNotifier;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TelegramBotTestSupport;

#[Group('postgres-release')]
final class ExpiredNotificationFailureTest extends TelegramBotTestSupport
{
    public static function unavailableRecipients(): array
    {
        return [
            'blocked' => ['Forbidden: bot was blocked by the user'],
            'deactivated' => ['Forbidden: user is deactivated'],
        ];
    }

    #[DataProvider('unavailableRecipients')]
    public function test_unavailable_recipient_is_recorded_as_failed_not_sent_and_not_retried_forever(string $description): void
    {
        $order = $this->expiredOrder(12345);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => false, 'error_code' => 403, 'description' => $description,
        ], 403)]);

        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->artisan('orders:expire-pending')->assertSuccessful();
        app(TelegramOrderExpiredNotifier::class)->send($order->fresh());

        $order->refresh();
        $this->assertNull($order->telegram_expired_order_notified_at);
        $this->assertNull($order->telegram_expired_order_notification_started_at);
        $this->assertNotNull($order->telegram_expired_order_notification_failed_at);
        $this->assertSame('recipient_unavailable', $order->telegram_expired_order_notification_failure_code);
        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->payment_status);
        Http::assertSentCount(1);
    }

    public function test_temporary_transport_failure_remains_retryable_and_does_not_claim_delivery(): void
    {
        $order = $this->expiredOrder(12345);
        $available = false;
        Http::fake(['https://api.telegram.org/*/sendMessage' => function () use (&$available) {
            return $available
                ? Http::response(['ok' => true, 'result' => ['message_id' => 321]])
                : Http::response(['ok' => false], 503);
        }]);

        $this->artisan('orders:expire-pending')->assertFailed();
        $order->refresh();
        $this->assertNull($order->telegram_expired_order_notified_at);
        $this->assertNull($order->telegram_expired_order_notification_started_at);
        $this->assertNull($order->telegram_expired_order_notification_failed_at);

        $available = true;
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertNotNull($order->fresh()->telegram_expired_order_notified_at);
        $this->assertNull($order->fresh()->telegram_expired_order_notification_failed_at);
    }

    public function test_an_unrecognized_forbidden_response_is_not_silently_classified_as_a_blocked_recipient(): void
    {
        $order = $this->expiredOrder(12345);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: unexpected bot permission failure',
        ], 403)]);

        $this->artisan('orders:expire-pending')->assertFailed();
        $this->assertNull($order->fresh()->telegram_expired_order_notification_failed_at);
        $this->assertNull($order->fresh()->telegram_expired_order_notified_at);
    }

    public function test_one_blocked_customer_does_not_prevent_another_customer_receiving_expiry(): void
    {
        $blocked = $this->expiredOrder(12345);
        $reachable = $this->expiredOrder(67890);
        Http::fake(['https://api.telegram.org/*/sendMessage' => fn ($request) => (string) $request['chat_id'] === '12345'
            ? Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)
            : Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertNotNull($blocked->fresh()->telegram_expired_order_notification_failed_at);
        $this->assertNull($blocked->fresh()->telegram_expired_order_notified_at);
        $this->assertNotNull($reachable->fresh()->telegram_expired_order_notified_at);
        $this->assertNull($reachable->fresh()->telegram_expired_order_notification_failed_at);
        Http::assertSentCount(2);
    }

    private function expiredOrder(int $telegramId): Order
    {
        return Order::factory()->for(Customer::factory()->create(['telegram_id' => $telegramId]))->create([
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
