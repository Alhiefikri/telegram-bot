<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileSakurupiahPayments;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SakurupiahEnvironment;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Services\OrderLifecycle;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahProviderLookupBudget;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
        foreach (['active', 'released'] as $lane) {
            Cache::forget('sakurupiah:reconcile:cursor:'.SakurupiahEnvironment::runtime()->value.':'.$lane);
        }
    }

    public function test_provider_throttle_stops_both_lanes_preserves_unvisited_payments_and_resumes_after_cooldown(): void
    {
        $first = $this->sakurupiahPayment();
        $next = $this->sakurupiahPayment();
        $late = $this->sakurupiahPayment();
        app(OrderLifecycle::class)->applyPaymentExpired($late);
        $lateState = $late->fresh()->provider_create_state;
        Http::fakeSequence()
            ->push(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 400)
            ->push(['status' => '200', 'data' => [['status' => 'pending']]])
            ->push(['status' => '200', 'data' => [['status' => 'pending']]])
            ->push(['status' => '200', 'data' => [['status' => 'pending']]])
            ->push(['status' => '200', 'data' => [['status' => 'pending']]]);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('stop_reason=provider_throttled carry_over=yes')
            ->assertExitCode(1);
        Http::assertSentCount(1);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $next->fresh()->provider_create_state);
        $this->assertSame($lateState, $late->fresh()->provider_create_state);
        $this->assertSame(PaymentStatus::Pending, $first->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $next->fresh()->status);
        $this->assertSame(PaymentStatus::Expired, $late->fresh()->status);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(1);
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        Http::assertSent(fn ($request): bool => $request['trx_id'] === $next->provider_reference);
        Http::assertSent(fn ($request): bool => $request['trx_id'] === $late->provider_reference);
        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        $this->assertSame(2, Http::recorded(fn ($request): bool => ($request['trx_id'] ?? null) === $first->provider_reference)->count());
        $this->assertSame(3, Payment::query()->count());
        $this->assertSame(0, ManualFulfillmentReview::query()->count());
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

    public function test_command_treats_amount_history_outage_as_verification_pending(): void
    {
        $payment = $this->sakurupiahPayment(['merchant_ref' => 'SAKU-HISTORY-OUTAGE']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->once()
            ->with((string) $payment->provider_reference)
            ->andReturn($this->providerStatus($payment, SakurupiahTransactionStatus::Successful));
        $provider->shouldReceive('transactionHistory')
            ->once()
            ->andThrow(new \RuntimeException('provider history outage'));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('paid=0 expired=0 pending=0 verification_pending=1 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(
            SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
            $payment->fresh()->provider_create_state,
        );
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

    public function test_provider_expired_payment_is_rechecked_at_a_lower_frequency(): void
    {
        $this->freezeTime();
        $payment = $this->sakurupiahPayment();
        Http::fake(['*status-transaction.php' => Http::response([
            'status' => '200', 'data' => [['status' => 'expired']],
        ])]);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        Http::assertSentCount(1);

        foreach (range(1, 14) as $_minute) {
            $this->travel(1)->minute();
            $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        }
        Http::assertSentCount(1);

        $this->travel(1)->minute();
        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        Http::assertSentCount(2);
    }

    public function test_active_payments_remain_frequent_and_late_money_is_reviewed_when_recheck_is_due(): void
    {
        $this->freezeTime();
        $active = $this->sakurupiahPayment();
        $late = $this->sakurupiahPayment();
        app(OrderLifecycle::class)->applyPaymentExpired($late);
        $latePaid = false;
        Http::fake(['*status-transaction.php' => function ($request) use ($late, &$latePaid) {
            $isLate = $request['trx_id'] === $late->provider_reference;
            $data = ['status' => $isLate ? ($latePaid ? 'berhasil' : 'expired') : 'pending'];
            if ($isLate && $latePaid) {
                $data['amount'] = (string) $late->amount;
            }

            return Http::response(['status' => '200', 'data' => [$data]]);
        }]);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        Http::assertSentCount(2);
        $latePaid = true;
        $this->travel(1)->minute();
        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        Http::assertSentCount(3);
        $this->assertSame(PaymentStatus::Expired, $late->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $active->fresh()->status);

        $this->travel(14)->minutes();
        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        Http::assertSentCount(5);
        $this->assertSame(PaymentStatus::Paid, $late->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $late->order->fresh()->order_status);
        $this->assertSame(1, ManualFulfillmentReview::query()->where('payment_id', $late->id)->where('reason', ManualFulfillmentReview::REASON_LATE_PAYMENT)->count());
        $this->assertSame(0, InventoryMovement::query()->where('order_id', $late->order_id)->where('type', InventoryMovementType::Sale)->count());
    }

    public function test_repeated_expired_checks_do_not_trigger_a_simulated_provider_limit(): void
    {
        $this->freezeTime();
        foreach (range(1, 3) as $_) {
            $payment = $this->sakurupiahPayment();
            app(OrderLifecycle::class)->applyPaymentExpired($payment);
        }
        $active = null;
        $rateEnabled = false;
        $callsByMinute = [];
        Http::fake(['*status-transaction.php' => function ($request) use (&$active, &$rateEnabled, &$callsByMinute) {
            if ($rateEnabled) {
                $minute = now()->format('Y-m-d H:i');
                $callsByMinute[$minute] = ($callsByMinute[$minute] ?? 0) + 1;
                if ($callsByMinute[$minute] > 2) {
                    return Http::response(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 400);
                }
            }

            return Http::response(['status' => '200', 'data' => [[
                'status' => $active !== null && $request['trx_id'] === $active->provider_reference ? 'pending' : 'expired',
            ]]]);
        }]);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        Http::assertSentCount(3);
        $active = $this->sakurupiahPayment();
        $rateEnabled = true;

        foreach (range(1, 5) as $_minute) {
            $this->travel(1)->minute();
            $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        }
        $this->assertSame([1, 1, 1, 1, 1], array_values($callsByMinute));
        $this->assertSame(PaymentStatus::Pending, $active->fresh()->status);
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

    public function test_command_rotates_cursor_between_bounded_runs(): void
    {
        $payments = collect(range(1, 3))->map(function (int $index): Payment {
            $payment = $this->sakurupiahPayment([
                'merchant_ref' => 'SAKU-CURSOR-'.$index,
            ]);
            $payment->forceFill(['created_at' => now()->subMinutes(20 - $index)])->save();

            return $payment;
        });

        $calledRefs = [];
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->times(3)
            ->andReturnUsing(function (string $ref) use (&$calledRefs, $payments): SakurupiahStatus {
                $calledRefs[] = $ref;
                $payment = $payments->first(fn (Payment $payment): bool => $payment->provider_reference === $ref);

                return $this->providerStatus($payment, SakurupiahTransactionStatus::Pending);
            });
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=2')
            ->expectsOutputToContain('paid=0 expired=0 pending=2 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);
        $this->artisan('sakurupiah:reconcile --chunk=2')
            ->expectsOutputToContain('paid=0 expired=0 pending=1 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);
        $this->assertSame($payments->pluck('provider_reference')->all(), $calledRefs);
    }

    public function test_cursor_preserves_created_at_precision_for_same_second_payments(): void
    {
        $createdAt = now()->startOfSecond()->subMinute();
        $payments = collect(range(1, 3))->map(function (int $index) use ($createdAt): Payment {
            $payment = $this->sakurupiahPayment([
                'merchant_ref' => 'SAKU-PRECISION-'.$index,
            ]);
            DB::table('payments')->where('id', $payment->getKey())->update([
                'created_at' => $createdAt->copy()->addMicroseconds($index * 100)->format('Y-m-d H:i:s.u'),
            ]);

            return $payment;
        });

        $calledRefs = [];
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->times(3)
            ->andReturnUsing(function (string $ref) use (&$calledRefs, $payments): SakurupiahStatus {
                $calledRefs[] = $ref;
                $payment = $payments->first(fn (Payment $payment): bool => $payment->provider_reference === $ref);

                return $this->providerStatus($payment, SakurupiahTransactionStatus::Pending);
            });
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        foreach (range(1, 3) as $_run) {
            $this->artisan('sakurupiah:reconcile --chunk=1')
                ->expectsOutputToContain('paid=0 expired=0 pending=1 verification_pending=0 invalid=0 busy=0 failed=0')
                ->assertExitCode(0);
        }

        $this->assertSame($payments->pluck('provider_reference')->all(), $calledRefs);
    }

    public function test_failed_payment_is_retried_before_cursor_advances(): void
    {
        $payments = collect(range(1, 2))->map(function (int $index): Payment {
            $payment = $this->sakurupiahPayment([
                'merchant_ref' => 'SAKU-RETRY-'.$index,
            ]);
            $payment->forceFill(['created_at' => now()->subMinutes(20 - $index)])->save();

            return $payment;
        });

        $statusCalls = 0;
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->times(3)
            ->andReturnUsing(function (string $reference) use (&$statusCalls, $payments): SakurupiahStatus {
                $statusCalls++;
                $payment = $payments->first(
                    fn (Payment $payment): bool => $payment->provider_reference === $reference,
                );

                return $this->providerStatus(
                    $payment,
                    $statusCalls === 1
                        ? SakurupiahTransactionStatus::Successful
                        : SakurupiahTransactionStatus::Pending,
                    $statusCalls === 1 ? (int) $payment->amount : null,
                );
            });
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $orders = Mockery::mock(OrderLifecycle::class);
        $orders->shouldReceive('applyPaymentPaid')
            ->once()
            ->andThrow(new \RuntimeException('transient order update failure'));
        $this->app->instance(OrderLifecycle::class, $orders);

        $this->artisan('sakurupiah:reconcile --chunk=2')
            ->expectsOutputToContain('failed=1')
            ->assertExitCode(1);
        $this->artisan('sakurupiah:reconcile --chunk=2')
            ->expectsOutputToContain('pending=2 verification_pending=0 invalid=0 busy=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(3, $statusCalls);
    }

    public function test_lookup_budget_covers_status_and_history_retry_chain(): void
    {
        config()->set('services.sakurupiah.lookup_max_attempts', 5);
        config()->set('services.sakurupiah.http_timeout_seconds', 60);
        config()->set('services.sakurupiah.lookup_retry_delay_ms', 1000);
        config()->set('services.sakurupiah.lookup_retry_max_delay_ms', 1000);
        config()->set('services.sakurupiah.reconcile_max_execution_seconds', 1);

        $this->assertSame(616, SakurupiahProviderLookupBudget::requiredLeaseSeconds());
        $this->assertSame(11, ReconcileSakurupiahPayments::schedulerMutexMinutes());
    }

    public function test_command_retries_a_rejected_create_on_the_same_reference_and_delivers_the_qr(): void
    {
        $payment = $this->sakurupiahPayment([
            'merchant_ref' => 'SAKU-REJECTED',
            'provider_reference' => null,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_fee' => null,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED,
            'provider_create_last_error' => 'application_status:400|provider_message:Gagal membuat Invoice',
            'provider_create_attempted_at' => now()->subMinutes(5),
        ]);

        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->andReturnUsing(fn (): SakurupiahStatus => $this->providerStatus($payment->fresh(), SakurupiahTransactionStatus::Pending));
        $provider->shouldReceive('transactionHistory')->never();
        $provider->shouldReceive('createInvoice')->once()->andReturn($this->payableInvoice($payment));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('pending=1 verification_pending=0 invalid=0 busy=0 failed=0 recovered=1')
            ->assertExitCode(0);

        $fresh = $payment->fresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $fresh->provider_create_state);
        $this->assertSame('trx-retried', $fresh->provider_reference);
        $this->assertNotNull($fresh->provider_presentation);
        $this->assertSame(
            1,
            TelegramOutboundEffect::query()
                ->where('effect_key', TelegramOutboundEffectService::sakurupiahPresentationRecoveryEffectKey((string) $payment->getKey()))
                ->count(),
        );

        // A later sweep reuses the persisted presentation instead of creating
        // a second provider invoice.
        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);
        $this->assertSame('trx-retried', $payment->fresh()->provider_reference);
    }

    public function test_command_keeps_a_rejected_create_retryable_inside_the_retry_interval(): void
    {
        $payment = $this->sakurupiahPayment([
            'merchant_ref' => 'SAKU-THROTTLED',
            'provider_reference' => null,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_fee' => null,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED,
            'provider_create_last_error' => 'application_status:400',
            'provider_create_attempted_at' => now(),
        ]);

        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->never();
        $provider->shouldReceive('transactionStatus')->never();
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')
            ->expectsOutputToContain('verification_pending=1')
            ->assertExitCode(0);

        $fresh = $payment->fresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED, $fresh->provider_create_state);
        $this->assertNull($fresh->provider_reference);
    }

    public function test_rejected_checkout_recovers_before_terminal_lookups_exhaust_the_sweep(): void
    {
        config()->set('services.sakurupiah.reconcile_max_execution_seconds', 1);
        $terminal = $this->sakurupiahPayment(['created_at' => now()->subDay()]);
        app(OrderLifecycle::class)->applyPaymentExpired($terminal);
        $checkout = $this->sakurupiahPayment([
            'provider_reference' => null,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_fee' => null,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED,
            'provider_create_last_error' => 'application_status:400',
            'provider_create_attempted_at' => now()->subMinutes(2),
        ]);
        $checkout->order->update(['expires_at' => now()->addMinutes(13)]);

        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')
            ->with((string) $terminal->provider_reference)
            ->andReturnUsing(function (): never {
                ProviderVerificationProfiles::pause(1_000);
                throw new \RuntimeException('The lookup deadline should stop this request.');
            });
        $provider->shouldReceive('createInvoice')->andReturn($this->payableInvoice($checkout));
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);

        $this->assertSame('trx-retried', $checkout->fresh()->provider_reference);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $checkout->fresh()->provider_create_state);
        $this->assertTrue(TelegramOutboundEffect::query()
            ->where('effect_key', TelegramOutboundEffectService::sakurupiahPresentationRecoveryEffectKey((string) $checkout->id))
            ->exists());
        $this->assertSame(OrderStatus::PendingPayment, $checkout->order->fresh()->order_status);
    }

    public function test_terminal_lookup_does_not_extend_the_late_payment_window(): void
    {
        $payment = $this->sakurupiahPayment([
            'created_at' => now()->subDays(5),
            'expired_at' => now()->subDays(4),
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATED,
        ]);
        app(OrderLifecycle::class)->applyPaymentExpired($payment);
        $payment->forceFill(['updated_at' => now()])->save();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $provider->shouldReceive('transactionHistory')->never();
        $provider->shouldReceive('createInvoice')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $payment->order->fresh()->order_status);
    }

    public function test_active_lookup_deadline_still_leaves_time_to_settle_late_money(): void
    {
        config()->set('services.sakurupiah.reconcile_max_execution_seconds', 1);
        $active = $this->sakurupiahPayment();
        $late = $this->sakurupiahPayment();
        app(OrderLifecycle::class)->applyPaymentExpired($late);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->with((string) $active->provider_reference)
            ->andReturnUsing(function (): never {
                ProviderVerificationProfiles::pause(1_000);
                throw new \RuntimeException('The active lookup should exhaust its time slice.');
            });
        $provider->shouldReceive('transactionStatus')->with((string) $late->provider_reference)
            ->andReturn($this->providerStatus($late, SakurupiahTransactionStatus::Successful, (int) $late->amount));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Pending, $active->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $late->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $late->order->fresh()->order_status);
        $this->assertSame(1, ManualFulfillmentReview::query()->where('payment_id', $late->id)->count());
    }

    public function test_old_uncertain_money_still_reconciles_into_late_payment_review(): void
    {
        $late = $this->sakurupiahPayment([
            'created_at' => now()->subDays(5),
            'expired_at' => now()->subDays(4),
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
        ]);
        app(OrderLifecycle::class)->applyPaymentExpired($late);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->with((string) $late->provider_reference)
            ->andReturn($this->providerStatus($late, SakurupiahTransactionStatus::Successful, (int) $late->amount));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->artisan('sakurupiah:reconcile --chunk=10')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $late->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $late->order->fresh()->order_status);
        $this->assertSame(1, ManualFulfillmentReview::query()->where('payment_id', $late->id)->count());
    }

    private function payableInvoice(Payment $payment, string $trxId = 'trx-retried'): SakurupiahInvoice
    {
        $amount = (int) $payment->amount;
        $total = $amount + 84;

        return new SakurupiahInvoice(
            trxId: $trxId,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: $total,
            amount: $amount,
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            checkoutUrl: 'https://sakurupiah.id/pay/invoice',
            sanitizedPayload: [
                'status' => '200',
                'data' => [[
                    'trx_id' => $trxId,
                    'merchant_ref' => $payment->merchant_ref,
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'amount' => (string) $amount,
                    'total' => (string) $total,
                    'fee' => '84',
                    'checkout_url' => 'https://sakurupiah.id/pay/invoice',
                ]],
            ],
            merchantFee: 1,
        );
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
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_total_payment' => $amount + 84,
            'provider_fee' => 84,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATED,
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
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
            'payment_kode' => SakurupiahCreateInvoice::METHOD_QRISC,
            'payment_status' => $status->value,
        ];
        if ($amount !== null) {
            $data['amount'] = (string) $amount;
        }

        return new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: $status,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            amount: $amount === null ? null : SakurupiahAmount::from($amount),
            sanitizedPayload: ['status' => '200', 'data' => [$data]],
        );
    }
}
