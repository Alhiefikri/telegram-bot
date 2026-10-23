<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\VerifyProviderPaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\TelegramNavigationRoot;
use App\Services\Telegram\TelegramOrderIntents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

class ProviderStatusVerificationTest extends TelegramBotTestSupport
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'database');
        KlikQrisSandboxScenarios::configureReadySandbox();
    }

    public static function providers(): array
    {
        return [['klikqris'], ['pakasir']];
    }

    public static function providerFailures(): array
    {
        return [
            ['klikqris', true], ['pakasir', true],
            ['klikqris', 'timeout'], ['pakasir', 'timeout'],
        ];
    }

    #[DataProvider('providers')]
    public function test_repeated_checks_coalesce_and_settle_only_once_without_waiting_for_provider(string $provider): void
    {
        [$customer, $order, $payment] = $this->pendingPayment($provider);
        $this->providerFixture($payment, 'paid');
        for ($i = 0; $i < 8; $i++) {
            app(TelegramOrderIntents::class)->sendOrderDetail($customer, '12345', $order->invoice_number);
        }
        Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), 'api.telegram.org'));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'payments')->count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        $this->work();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(1, PaymentEvent::whereNotNull('processed_at')->count());
        $before = Http::recorded()->count();
        app()->call([$this->job($payment, $customer), 'handle']);
        $this->assertSame($before, Http::recorded()->count());
        $this->assertSame(1, PaymentEvent::whereNotNull('processed_at')->count());
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_stays_pending_and_same_job_can_recover(string $provider, bool|string $failure): void
    {
        [$customer, $order, $payment] = $this->pendingPayment($provider);
        $outage = $failure;
        $this->providerFixture($payment, 'paid', $outage);
        app(TelegramOrderIntents::class)->sendOrderDetail($customer, '12345', $order->invoice_number);
        $this->work();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, PaymentEvent::whereNotNull('processed_at')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'payments')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $outage = false;
        $this->travel(61)->seconds();
        $this->work();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, PaymentEvent::whereNotNull('processed_at')->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'payments')->count());
    }

    #[DataProvider('providers')]
    public function test_lost_verification_wakeup_is_recovered_by_existing_reconciliation(string $provider): void
    {
        [$customer, $order, $payment] = $this->pendingPayment($provider);
        $this->providerFixture($payment, 'paid');
        app(TelegramOrderIntents::class)->sendOrderDetail($customer, '12345', $order->invoice_number);
        DB::table('jobs')->where('queue', 'payments')->delete();

        $this->artisan($provider.':reconcile')->assertSuccessful();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(1, PaymentEvent::whereNotNull('processed_at')->count());
        $this->assertSame(1, Payment::count());
    }

    #[DataProvider('providers')]
    public function test_queued_lookup_rechecks_customer_and_provider_binding_before_http(string $provider): void
    {
        [$customer, $order, $payment] = $this->pendingPayment($provider);
        $this->providerFixture($payment, 'paid');
        app()->call([$this->job($payment, Customer::factory()->create()), 'handle']);
        $job = $this->job($payment, $customer);
        $payment->forceFill(['provider' => 'manual'])->save();
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    #[DataProvider('providers')]
    public function test_foreign_invoice_cannot_enqueue_provider_lookup(string $provider): void
    {
        [, $order] = $this->pendingPayment($provider);
        $other = Customer::factory()->create(['telegram_id' => 54321]);
        $this->fakeTelegramHttp();
        app(TelegramOrderIntents::class)->sendOrderDetail($other, '54321', $order->invoice_number);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'payments')->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), $order->invoice_number));
    }

    #[DataProvider('providers')]
    public function test_delayed_result_does_not_edit_new_navigation_root(string $provider): void
    {
        [$customer, $order, $payment] = $this->pendingPayment($provider);
        $this->providerFixture($payment, 'paid');
        app(TelegramOrderIntents::class)->sendOrderDetail($customer, '12345', $order->invoice_number);
        $this->postTelegramWebhook($this->messageUpdate(7101, '/start'))->assertOk();
        $this->artisan('queue:work --once --queue=default --stop-when-empty')->assertSuccessful();
        $root = TelegramNavigationRoot::query()->where('customer_id', $customer->id)->sole();
        $snapshot = $root->getAttributes();
        $before = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'))->count();
        $this->work();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame($snapshot, $root->fresh()->getAttributes());
        $this->assertSame($before, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'))->count());
    }

    private function pendingPayment(string $provider): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 1000,
            'expires_at' => now()->addMinutes(14),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => $provider,
            'provider_payment_method' => 'qris',
            'merchant_ref' => $order->invoice_number,
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_expires_at' => now()->addMinutes(15),
            'status' => PaymentStatus::Pending,
            'provider_create_state' => 'created',
            'provider_payload' => ['data' => [
                'order_id' => $order->invoice_number,
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/local.png',
                'expired_at' => now()->addMinutes(15)->toISOString(),
            ]],
        ]);

        return [$customer, $order, $payment];
    }

    private function providerFixture(Payment $payment, string $status, bool|string|null &$outage = null): void
    {
        $this->fakeTelegramHttp([
            'https://klikqris.com/api/sandbox/qris/status/*' => function () use ($payment, $status, &$outage) {
                if ($outage === 'timeout') {
                    return Http::failedConnection();
                }

                return Http::response($outage ? [] : ['data' => [
                    'order_id' => $payment->merchant_ref, 'amount' => 1000, 'total_amount' => 1016,
                    'status' => $status === 'paid' ? 'SUCCESS' : 'PENDING',
                    'paid_at' => now()->toISOString(),
                ]], $outage ? 503 : 200);
            },
            'https://app.pakasir.test/api/transactiondetail*' => function () use ($payment, $status, &$outage) {
                if ($outage === 'timeout') {
                    return Http::failedConnection();
                }

                return Http::response($outage ? [] : ['transaction' => [
                    'project' => 'sedulur-digital', 'order_id' => $payment->merchant_ref,
                    'amount' => 1000, 'status' => $status === 'paid' ? 'completed' : 'pending',
                    'payment_method' => 'qris',
                    'completed_at' => now()->toISOString(),
                ]], $outage ? 503 : 200);
            },
        ]);
    }

    private function job(Payment $payment, Customer $customer): VerifyProviderPaymentStatus
    {
        return new VerifyProviderPaymentStatus($payment->id, (string) $customer->id, $payment->provider, now()->toISOString());
    }

    private function work(): void
    {
        $this->artisan('queue:work --once --queue=payments --stop-when-empty')->assertSuccessful();
    }
}
