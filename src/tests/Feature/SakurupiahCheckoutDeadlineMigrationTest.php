<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SakurupiahCheckoutDeadlineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutover_caps_only_pending_sakurupiah_orders_without_extending_shorter_deadlines_or_changing_provider_history(): void
    {
        $this->travelTo('2026-10-27 07:00:00');
        $createdAt = now()->subHour();
        $providerDeadline = $createdAt->copy()->addDay();
        $pending = Order::factory()->create(['created_at' => $createdAt, 'expires_at' => $providerDeadline]);
        $payment = Payment::factory()->for($pending, 'order')->create([
            'provider' => 'sakurupiah',
            'expired_at' => $providerDeadline,
            'provider_expires_at' => $providerDeadline,
            'provider_presentation' => ['mode' => 'hosted', 'effective_expires_at' => $providerDeadline->toIso8601String()],
        ]);
        $shortDeadline = $createdAt->copy()->addMinutes(5);
        $short = Order::factory()->create(['created_at' => $createdAt, 'expires_at' => $shortDeadline]);
        $shortPayment = Payment::factory()->for($short, 'order')->create(['provider' => 'sakurupiah', 'expired_at' => $shortDeadline]);
        $other = Order::factory()->create(['created_at' => $createdAt, 'expires_at' => $providerDeadline]);
        $otherPayment = Payment::factory()->for($other, 'order')->create(['provider' => 'klikqris', 'expired_at' => $providerDeadline]);
        $paid = Order::factory()->create(['created_at' => $createdAt, 'expires_at' => $providerDeadline, 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->for($paid, 'order')->create(['provider' => 'sakurupiah', 'status' => PaymentStatus::Paid, 'expired_at' => $providerDeadline]);

        $migration = require database_path('migrations/2026_10_27_000002_cap_pending_sakurupiah_checkout_deadlines.php');
        $migration->up();
        $migration->up();
        $deadline = $createdAt->copy()->addMinutes(15);
        $this->assertSame($deadline->timestamp, $pending->fresh()->expires_at->timestamp);
        $this->assertSame($deadline->timestamp, $payment->fresh()->expired_at->timestamp);
        $this->assertSame($deadline->toIso8601String(), $payment->fresh()->provider_presentation['effective_expires_at']);
        $this->assertSame($providerDeadline->timestamp, $payment->fresh()->provider_expires_at->timestamp);
        $this->assertSame(OrderStatus::PendingPayment, $pending->fresh()->order_status);
        $this->assertSame($shortDeadline->timestamp, $short->fresh()->expires_at->timestamp);
        $this->assertSame($shortDeadline->timestamp, $shortPayment->fresh()->expired_at->timestamp);
        $this->assertSame($providerDeadline->timestamp, $other->fresh()->expires_at->timestamp);
        $this->assertSame($providerDeadline->timestamp, $otherPayment->fresh()->expired_at->timestamp);
        $this->assertSame($providerDeadline->timestamp, $paid->fresh()->expires_at->timestamp);
        $migration->down();
        $this->assertSame($deadline->timestamp, $pending->fresh()->expires_at->timestamp);
    }

    #[DataProvider('terminalOrders')]
    public function test_cutover_never_reopens_or_retimes_terminal_orders(string $orderStatus, string $paymentStatus): void
    {
        $this->travelTo('2026-10-27 07:00:00');
        $order = Order::factory()->create([
            'created_at' => '2026-10-27 06:00:00',
            'expires_at' => '2026-10-28 06:00:00',
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'sakurupiah',
            'status' => 'pending',
            'expired_at' => '2026-10-28 06:00:00',
        ]);

        (require database_path('migrations/2026_10_27_000002_cap_pending_sakurupiah_checkout_deadlines.php'))->up();

        $this->assertSame($orderStatus, $order->fresh()->order_status->value);
        $this->assertSame($paymentStatus, $order->fresh()->payment_status->value);
        $this->assertSame('2026-10-28 06:00:00', $order->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 06:00:00', $payment->fresh()->expired_at->format('Y-m-d H:i:s'));
    }

    public static function terminalOrders(): array
    {
        return [
            'cancelled' => ['cancelled', 'pending'],
            'expired' => ['expired', 'expired'],
            'completed' => ['completed', 'paid'],
            'processing' => ['processing', 'paid'],
            'paid projection on pending order' => ['pending_payment', 'paid'],
        ];
    }

    #[DataProvider('missingDeadlines')]
    public function test_cutover_supplies_missing_local_deadlines_without_replacing_provider_money_or_encrypted_instructions(?string $orderDeadline, ?string $paymentDeadline): void
    {
        $this->travelTo('2026-10-27 07:00:00');
        $order = Order::factory()->create(['created_at' => '2026-10-27 06:00:00', 'expires_at' => $orderDeadline]);
        $presentation = ['mode' => 'hosted', 'checkout_url' => 'https://sakurupiah.id/pay/owned-invoice', 'effective_expires_at' => '2026-10-28T06:00:00+07:00'];
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'sakurupiah',
            'expired_at' => $paymentDeadline,
            'provider_expires_at' => '2026-10-28 06:00:00',
            'provider_reference' => 'owned-provider-invoice',
            'amount' => 12000,
            'provider_total_payment' => 12084,
            'provider_fee' => 84,
            'provider_presentation' => $presentation,
        ]);

        (require database_path('migrations/2026_10_27_000002_cap_pending_sakurupiah_checkout_deadlines.php'))->up();

        $fresh = $payment->fresh();
        $this->assertSame('2026-10-27 06:15:00', $order->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27 06:15:00', $fresh->expired_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 06:00:00', $fresh->provider_expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('owned-provider-invoice', $fresh->provider_reference);
        $this->assertSame(12000, $fresh->amount);
        $this->assertSame(12084, $fresh->provider_total_payment);
        $this->assertSame(84, $fresh->provider_fee);
        $presentation['effective_expires_at'] = '2026-10-27T06:15:00+07:00';
        $this->assertSame($presentation, $fresh->provider_presentation);
        $raw = DB::table('payments')->where('id', $payment->id)->value('provider_presentation');
        $this->assertStringNotContainsString('https://sakurupiah.id/pay/owned-invoice', $raw);
    }

    public static function missingDeadlines(): array
    {
        return [
            'both missing' => [null, null],
            'order missing' => [null, '2026-10-28 06:00:00'],
            'payment missing' => ['2026-10-28 06:00:00', null],
        ];
    }

    public function test_cutover_skips_order_with_a_paid_payment_even_if_order_projection_is_still_pending(): void
    {
        $order = Order::factory()->create(['created_at' => '2026-10-27 06:00:00', 'expires_at' => '2026-10-28 06:00:00']);
        $pending = Payment::factory()->for($order)->create(['provider' => 'sakurupiah', 'expired_at' => '2026-10-28 06:00:00']);
        Payment::factory()->for($order)->create(['provider' => 'sakurupiah', 'status' => PaymentStatus::Paid]);

        (require database_path('migrations/2026_10_27_000002_cap_pending_sakurupiah_checkout_deadlines.php'))->up();

        $this->assertSame('2026-10-28 06:00:00', $order->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 06:00:00', $pending->fresh()->expired_at->format('Y-m-d H:i:s'));
    }

    public function test_cutover_preserves_earlier_payment_and_presentation_deadlines_independently(): void
    {
        $order = Order::factory()->create(['created_at' => '2026-10-27 06:00:00', 'expires_at' => '2026-10-28 06:00:00']);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'sakurupiah',
            'expired_at' => '2026-10-27 06:10:00',
            'provider_presentation' => ['effective_expires_at' => '2026-10-27T06:05:00+07:00'],
        ]);

        (require database_path('migrations/2026_10_27_000002_cap_pending_sakurupiah_checkout_deadlines.php'))->up();

        $this->assertSame('2026-10-27 06:15:00', $order->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27 06:10:00', $payment->fresh()->expired_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27T06:05:00+07:00', $payment->fresh()->provider_presentation['effective_expires_at']);
    }

    public function test_invalid_encrypted_presentation_rolls_back_the_orders_deadline_instead_of_partially_migrating_it(): void
    {
        $order = Order::factory()->create(['created_at' => '2026-10-27 06:00:00', 'expires_at' => '2026-10-28 06:00:00']);
        $payment = Payment::factory()->for($order)->create(['provider' => 'sakurupiah', 'expired_at' => '2026-10-28 06:00:00']);
        DB::table('payments')->where('id', $payment->id)->update(['provider_presentation' => 'corrupt-encrypted-value']);
        $this->expectException(DecryptException::class);

        try {
            (require database_path('migrations/2026_10_27_000002_cap_pending_sakurupiah_checkout_deadlines.php'))->up();
        } finally {
            $this->assertSame('2026-10-28 06:00:00', $order->fresh()->expires_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-28 06:00:00', $payment->fresh()->expired_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_cutover_finishes_beyond_the_first_page_of_pending_orders(): void
    {
        $orders = Order::factory()->count(101)->create(['created_at' => '2026-10-27 06:00:00', 'expires_at' => '2026-10-28 06:00:00']);
        foreach ($orders as $order) {
            Payment::factory()->for($order)->create(['provider' => 'sakurupiah', 'expired_at' => '2026-10-28 06:00:00']);
        }

        (require database_path('migrations/2026_10_27_000002_cap_pending_sakurupiah_checkout_deadlines.php'))->up();

        $this->assertSame(101, Order::where('expires_at', '2026-10-27 06:15:00')->count());
        $this->assertSame(101, Payment::where('expired_at', '2026-10-27 06:15:00')->count());
        $this->assertSame(0, Order::where('expires_at', '>', '2026-10-27 06:15:00')->count());
    }
}
