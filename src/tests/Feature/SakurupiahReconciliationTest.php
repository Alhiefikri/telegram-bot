<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\OrderLifecycle;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SakurupiahReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
            'sandbox_allowed_ips' => ['8.8.8.8'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
            'reconcile_terminal_lookback_hours' => 72,
            'reconcile_max_execution_seconds' => 45,
        ]));
    }

    public function test_command_reconciles_authoritative_paid_and_leaves_pending_and_other_providers_untouched(): void
    {
        $success = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-PAID']);
        $pending = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-PENDING']);
        $other = Payment::factory()->create([
            'provider' => 'klikqris',
            'merchant_ref' => 'KLIK-REF',
            'status' => PaymentStatus::Pending,
        ]);

        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->twice()
            ->andReturnUsing(function (string $ref) use ($success, $pending): SakurupiahStatus {
                if ($ref === (string) $success->provider_reference) {
                    return $this->providerStatus($success, SakurupiahTransactionStatus::Successful, amount: (int) $success->amount);
                }

                return $this->providerStatus($pending, SakurupiahTransactionStatus::Pending);
            });
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=1 expired=0 pending=1 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $success->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $success->order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $success->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $pending->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $other->fresh()->status);
    }

    public function test_command_never_creates_an_invoice_for_a_payment_without_confirmed_identity(): void
    {
        $payment = $this->sakurupiahPayment([
            'merchant_ref' => 'SAKU-NO-REF',
            'provider_reference' => null,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
        ]);

        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $provider->shouldReceive('createInvoice')->never();
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=0 expired=0 pending=0 verification_pending=1 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $fresh = $payment->fresh();
        $this->assertSame(PaymentStatus::Pending, $fresh->status);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED, $fresh->provider_create_state);
        $this->assertNull($fresh->provider_reference);
    }

    public function test_command_keeps_paid_verification_pending_when_status_has_no_amount_evidence(): void
    {
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-NO-AMOUNT']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->once()
            ->with((string) $payment->provider_reference)
            ->andReturn($this->providerStatus($payment, SakurupiahTransactionStatus::Successful));
        $provider->shouldReceive('transactionHistory')->once()->andReturn([]);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=0 expired=0 pending=0 verification_pending=1 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->order->fresh()->payment_status);
    }

    public function test_command_applies_authoritative_expired(): void
    {
        $expired = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-EXPIRED']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->once()
            ->with((string) $expired->provider_reference)
            ->andReturn($this->providerStatus($expired, SakurupiahTransactionStatus::Expired));
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=0 expired=1 pending=0 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Expired, $expired->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $expired->order->fresh()->order_status);
    }

    public function test_command_marks_late_paid_on_terminal_order_as_manual_review_without_fulfilling(): void
    {
        $late = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-LATE']);
        app(OrderLifecycle::class)->applyPaymentExpired($late);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->once()
            ->with((string) $late->provider_reference)
            ->andReturn($this->providerStatus($late, SakurupiahTransactionStatus::Successful, amount: (int) $late->amount));
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=1 expired=0 pending=0 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $fresh = $late->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->status);
        $this->assertSame(OrderStatus::Expired, $late->order->fresh()->order_status);
        $this->assertSame(1, ManualFulfillmentReview::query()->where('payment_id', $late->id)->where('reason', ManualFulfillmentReview::REASON_LATE_PAYMENT)->count());
        $this->assertSame(0, InventoryMovement::query()->where('order_id', $late->order_id)->where('type', InventoryMovementType::Sale)->count());
    }

    public function test_command_never_touches_a_payment_that_never_attempted_creation(): void
    {
        // A Pending Payment without a provider reference and without an
        // unresolved create state belongs to the post-commit create worker.
        // The reconcile sweep must not relabel it recovery_unconfirmed.
        $payment = $this->sakurupiahPayment([
            'merchant_ref' => 'SAKU-NEVER-CREATED',
            'provider_reference' => null,
            'provider_create_state' => null,
        ]);

        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $provider->shouldReceive('createInvoice')->never();
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=0 expired=0 pending=0 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $fresh = $payment->fresh();
        $this->assertSame(PaymentStatus::Pending, $fresh->status);
        $this->assertNull($fresh->provider_create_state);
        $this->assertNull($fresh->provider_reference);
    }

    public function test_duplicate_run_is_idempotent_and_does_not_issue_second_provider_call_for_paid(): void
    {
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-IDEMPOTENT']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->once()
            ->with((string) $payment->provider_reference)
            ->andReturn($this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: (int) $payment->amount));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=0 expired=0 pending=0 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_command_output_is_aggregate_and_never_leaks_identifiers_or_payloads(): void
    {
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-SECRET-REF']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->andThrow(new \RuntimeException('secret-key must not escape'));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        Artisan::call('sakurupiah:reconcile --chunk=10');
        $output = Artisan::output();

        $this->assertStringNotContainsString('secret-key', $output);
        $this->assertStringNotContainsString('SAKU-SECRET-REF', $output);
        $this->assertStringNotContainsString((string) $payment->provider_reference, $output);
    }

    public function test_command_bounds_chunk_and_uses_deterministic_ordering_without_n_plus_one(): void
    {
        $payments = collect(range(1, 3))->map(fn (int $index): Payment => $this->sakurupiahPayment([
            'merchant_ref' => 'SAKU-BOUND-'.$index,
        ]));

        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->twice()
            ->andReturnUsing(function (string $ref) use ($payments): SakurupiahStatus {
                $payment = $payments->first(fn (Payment $payment): bool => $payment->provider_reference === $ref);

                return $this->providerStatus($payment, SakurupiahTransactionStatus::Pending);
            });
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->artisan('sakurupiah:reconcile --chunk=2')
            ->expectsOutputToContain('paid=0 expired=0 pending=2 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        // The scan issues exactly one bounded Payment query (deterministic
        // order + limit). The order relation is eager-loaded once for the whole
        // chunk (multi-placeholder IN), not once per Payment row.
        $scanQueries = collect($queries)
            ->filter(fn (string $sql): bool => str_contains($sql, 'order by "created_at" asc, "id" asc limit 2'))
            ->count();
        $eagerOrderQueries = collect($queries)
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "orders" where "orders"."id" in (?, ?)'))
            ->count();
        $this->assertSame(1, $scanQueries, implode("\n", $queries));
        $this->assertSame(1, $eagerOrderQueries);
        Http::assertNothingSent();
    }

    public function test_schedule_registers_sakurupiah_reconcile_only_when_operationally_ready(): void
    {
        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('sakurupiah:reconcile --chunk=100', $schedule);
        $this->assertStringContainsString('ReconcileSakurupiahPayments::schedulerMutexMinutes()', $schedule);
        $this->assertStringContainsString('schedulingEnabledFor', $schedule);
    }

    private function sakurupiahPayment(array $attributes = []): Payment
    {
        $amount = (int) ($attributes['amount'] ?? 12000);
        $customer = Customer::factory()->create([
            'phone_number' => '08123456789',
            'email' => 'buyer+'.bin2hex(random_bytes(4)).'@example.test',
        ]);
        $order = Order::factory()->for($customer, 'customer')->create([
            'total_amount' => $amount,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 1]);
        OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
        ]);
        InventoryMovement::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => InventoryMovementType::Reservation,
            'quantity' => 1,
            'notes' => 'Order reservation',
            'created_at' => now(),
        ]);

        return Payment::factory()->for($order, 'order')->create(array_merge([
            'provider' => PaymentProvider::SAKURUPIAH,
            'merchant_ref' => 'SAKU-'.$order->id,
            'provider_reference' => 'trx-saku-'.$order->id,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRIS,
            'provider_total_payment' => $amount + 84,
            'provider_fee' => 84,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATED,
            'method' => SakurupiahCreateInvoice::METHOD_QRIS,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
        ], $attributes));
    }

    private function providerStatus(
        Payment $payment,
        SakurupiahTransactionStatus $status,
        ?int $amount = null,
    ): SakurupiahStatus {
        $data = [
            'trx_id' => $payment->provider_reference,
            'merchant_ref' => $payment->merchant_ref,
            'payment_kode' => SakurupiahCreateInvoice::METHOD_QRIS,
            'payment_status' => $status->value,
        ];
        if ($amount !== null) {
            $data['amount'] = (string) $amount;
        }

        return new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: $status,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRIS,
            amount: $amount === null ? null : SakurupiahAmount::from($amount),
            sanitizedPayload: ['status' => '200', 'data' => [$data]],
        );
    }
}
