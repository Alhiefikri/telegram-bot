<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileKlikQrisPayments;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->travelTo('2026-07-28 07:05:00');
    }

    public function test_command_fulfills_authoritative_success_and_skips_pending_without_touching_other_providers_or_paid_payments(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $success = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-SUCCESS']);
        $pending = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-PENDING']);
        $paid = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-PAID', 'status' => PaymentStatus::Paid]);
        $pakasir = Payment::factory()->create(['provider' => 'pakasir', 'merchant_ref' => 'PAKASIR-REF', 'status' => PaymentStatus::Pending]);

        Http::fake(function ($request) use ($success, $pending) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/qris/status/', $request->url());
            $this->assertStringNotContainsString('KLIK-PAID', $request->url());
            $this->assertStringNotContainsString('PAKASIR-REF', $request->url());

            return str_contains($request->url(), 'KLIK-PENDING')
                ? Http::response(KlikQrisSandboxScenarios::statusPayload($pending, ['status' => 'PENDING']), 200)
                : Http::response(KlikQrisSandboxScenarios::statusPayload($success), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=2')
            ->expectsOutputToContain('processed=1 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $success->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $success->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $pending->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $paid->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $pakasir->fresh()->status);
        Http::assertSentCount(2);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_command_ignores_old_terminal_history_but_keeps_outcome_unknown_recovery_candidates(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $oldTerminal = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-OLD-TERMINAL',
            'status' => PaymentStatus::Expired,
        ]);
        DB::table('payments')->where('id', $oldTerminal->getKey())->update([
            'updated_at' => now()->subHours(73),
        ]);
        $unknown = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-PAID-UNKNOWN',
            'status' => PaymentStatus::Paid,
            'provider_create_state' => 'outcome_unknown',
        ]);
        $recentTerminal = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-RECENT-FAILED',
            'status' => PaymentStatus::Failed,
        ]);
        DB::table('payments')->where('id', $recentTerminal->getKey())->update([
            'updated_at' => now()->subHour(),
        ]);

        Http::fake(function ($request) use ($unknown, $recentTerminal, $oldTerminal) {
            $this->assertStringNotContainsString((string) $oldTerminal->merchant_ref, $request->url());

            return str_contains($request->url(), (string) $unknown->merchant_ref)
                ? Http::response(KlikQrisSandboxScenarios::statusPayload($unknown), 200)
                : Http::response(KlikQrisSandboxScenarios::statusPayload($recentTerminal, ['status' => 'PENDING']), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=100 --max-payments=10')
            ->expectsOutputToContain('processed=1 skipped=1 failed=0')
            ->assertSuccessful();

        $this->assertSame(PaymentStatus::Expired, $oldTerminal->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $unknown->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $recentTerminal->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_command_stops_after_the_bounded_number_of_payments(): void
    {
        $payments = collect(range(1, 3))->map(fn (int $index): Payment => $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-BOUND-'.$index,
        ]));
        Http::fake(fn ($request) => Http::response(
            KlikQrisSandboxScenarios::statusPayload($payments->first(fn (Payment $payment): bool => str_contains($request->url(), (string) $payment->merchant_ref)), ['status' => 'PENDING']),
            200,
        ));

        $this->artisan('klikqris:reconcile --chunk=100 --max-payments=2')
            ->expectsOutputToContain('processed=0 skipped=2 failed=0')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_command_rotates_durable_cursor_so_newer_candidates_are_not_starved_by_always_eligible_older_rows(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $oldPending = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-OLD-PENDING',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        $newPending = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-NEW-PENDING',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $requested = [];
        Http::fake(function ($request) use ($oldPending, $newPending, &$requested) {
            $requested[] = $request->url();

            $payment = str_contains($request->url(), 'KLIK-OLD-PENDING') ? $oldPending : $newPending;

            return Http::response(
                KlikQrisSandboxScenarios::statusPayload($payment, ['status' => 'PENDING']),
                200,
            );
        });

        // Run 1: no persisted cursor yet, so the sweep starts at the oldest row.
        $this->artisan('klikqris:reconcile --chunk=100 --max-payments=1')
            ->expectsOutputToContain('processed=0 skipped=1 failed=0')
            ->assertSuccessful();
        $this->assertCount(1, $requested);
        $this->assertStringContainsString('KLIK-OLD-PENDING', $requested[0]);

        // Run 2: the durable cursor from run 1 makes the sweep resume after the
        // old row, so the newer candidate finally gets its turn.
        $this->artisan('klikqris:reconcile --chunk=100 --max-payments=1')
            ->expectsOutputToContain('processed=0 skipped=1 failed=0')
            ->assertSuccessful();
        $this->assertCount(2, $requested);
        $this->assertStringContainsString('KLIK-NEW-PENDING', $requested[1]);

        $this->assertSame(PaymentStatus::Pending, $oldPending->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $newPending->fresh()->status);
    }

    public function test_command_wraps_cursor_after_walking_the_whole_eligible_set(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-ONLY-PENDING',
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        $requested = [];
        Http::fake(function ($request) use ($payment, &$requested) {
            $requested[] = $request->url();

            return Http::response(
                KlikQrisSandboxScenarios::statusPayload($payment, ['status' => 'PENDING']),
                200,
            );
        });

        // First run walks the single eligible row to the end and wraps the cursor.
        $this->artisan('klikqris:reconcile --chunk=100 --max-payments=10')
            ->expectsOutputToContain('processed=0 skipped=1 failed=0')
            ->assertSuccessful();
        $this->assertCount(1, $requested);
        $this->assertNull(Cache::get('klikqris:reconcile:cursor:sandbox'));

        // A fresh run starts from the beginning again because the cursor wrapped.
        $this->artisan('klikqris:reconcile --chunk=100 --max-payments=10')
            ->expectsOutputToContain('processed=0 skipped=1 failed=0')
            ->assertSuccessful();
        $this->assertCount(2, $requested);
        $this->assertStringContainsString('KLIK-ONLY-PENDING', $requested[1]);
    }

    public function test_command_stops_when_the_execution_budget_is_exhausted(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $first = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-BUDGET-FIRST']);
        $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-BUDGET-SECOND']);

        Http::fake(fn ($request) => Http::response(
            KlikQrisSandboxScenarios::statusPayload($first, ['status' => 'PENDING']),
            200,
        ));

        // Inject a command whose execution deadline is already exhausted before
        // the first payment, so no provider lookup may start.
        $this->app->instance(
            ReconcileKlikQrisPayments::class,
            new ReconcileKlikQrisPayments(
                deadlineFactory: fn (): int => (int) hrtime(true),
            ),
        );

        $this->artisan('klikqris:reconcile --chunk=100 --max-payments=10')
            ->expectsOutputToContain('processed=0 skipped=0 failed=0')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_command_creates_pending_native_qris_and_verifies_it_without_customer_refresh(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_payload' => null,
            'provider_create_state' => null,
        ]);

        Http::fakeSequence()
            ->push(KlikQrisSandboxScenarios::createPayload($payment, [
                'total_amount' => 1016,
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_background.png',
            ]), 200)
            ->push(KlikQrisSandboxScenarios::statusPayload($payment, [
                'total_amount' => 1016,
            ]), 200);

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=1 skipped=0 failed=0')
            ->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $this->assertSame('created', $payment->provider_create_state);
        $this->assertSame(1016, $payment->provider_total_payment);
        $this->assertSame(1, $payment->paymentEvents()->where('event_type', 'provider_create')->count());
        $this->assertSame(1, $payment->paymentEvents()->where('event_type', 'reconciliation')->count());
        Http::assertSentCount(2);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_command_skips_payment_when_callback_holds_the_status_lock(): void
    {
        $payment = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-LOCKED']);
        $lock = Cache::lock('klikqris:callback-status:'.$payment->getKey(), 30);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->artisan('klikqris:reconcile --chunk=100')
                ->expectsOutputToContain('processed=0 skipped=1 failed=0')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_command_can_process_locally_expired_klikqris_success_when_provider_evidence_matches(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-EXPIRED']);
        $payment->order->update(['order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        $payment->update(['status' => PaymentStatus::Expired, 'expired_at' => now()->subMinute()]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=1 skipped=0 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);
    }

    public function test_command_continues_chunk_after_status_outage_and_sanitizes_output(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        config()->set('services.klikqris.sandbox_api_key', 'secret-api-key');
        $failed = $this->payableKlikQrisPayment(['merchant_ref' => 'MERCHANT-SECRET-REF']);
        $processed = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-AFTER-FAIL']);

        Http::fake(function ($request) use ($processed) {
            if (str_contains($request->url(), 'MERCHANT-SECRET-REF')) {
                throw new ConnectionException('socket failed with secret-api-key and opaque-test-signature at https://klikqris.com/private');
            }

            return Http::response(KlikQrisSandboxScenarios::statusPayload($processed), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=1')
            ->expectsOutputToContain('processed=1 skipped=0 failed=1')
            ->doesntExpectOutputToContain('MERCHANT-SECRET-REF')
            ->doesntExpectOutputToContain('secret-api-key')
            ->doesntExpectOutputToContain('opaque-test-signature')
            ->doesntExpectOutputToContain('https://klikqris.com/private')
            ->assertExitCode(1);

        $this->assertSame(PaymentStatus::Pending, $failed->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $processed->fresh()->status);
    }

    public function test_command_selects_outcome_unknown_but_never_calls_create_transaction(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-UNKNOWN',
            'status' => PaymentStatus::Failed,
            'provider_create_state' => 'outcome_unknown',
        ]);
        Http::fake(function ($request) use ($payment) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/qris/status/KLIK-UNKNOWN', $request->url());
            $this->assertStringNotContainsString('/qris/create', $request->url());

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment, ['status' => 'PENDING']), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=0 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_command_recovers_true_outcome_unknown_success_from_status_without_create_transaction(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-UNKNOWN-SUCCESS',
            'status' => PaymentStatus::Pending,
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_payload' => null,
            'provider_create_state' => 'outcome_unknown',
            'provider_create_last_error' => 'Illuminate\\Http\\Client\\ConnectionException',
        ]);

        Http::fake(function ($request) use ($payment) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/qris/status/KLIK-UNKNOWN-SUCCESS', $request->url());
            $this->assertStringNotContainsString('/qris/create', $request->url());

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200);
        });

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=1 skipped=0 failed=0')
            ->assertExitCode(0);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $this->assertSame('qris', $payment->provider_payment_method);
        $this->assertSame(1016, $payment->provider_total_payment);
        $this->assertSame('SUCCESS', $payment->provider_payload['data']['status'] ?? null);
        $this->assertNull($payment->provider_create_last_error);
        Http::assertSentCount(1);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_in_flight_backfill_recovers_old_creating_payment_during_rollback_without_creating_again(): void
    {
        $payment = $this->payableKlikQrisPayment([
            'merchant_ref' => 'KLIK-EXPAND-CREATING',
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_payload' => null,
            'provider_create_state' => 'creating',
        ]);
        config()->set('services.payment_lifecycle_rollout.mode', 'rollback');
        Http::fake(function ($request) use ($payment) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/qris/status/KLIK-EXPAND-CREATING', $request->url());
            $this->assertStringNotContainsString('/qris/create', $request->url());

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment, [
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_backfill.png',
                'expired_at' => '2026-07-28T07:20:00Z',
            ]), 200);
        });

        $this->artisan('klikqris:backfill-in-flight --chunk=100')
            ->expectsOutputToContain('recovered=1 skipped=0 failed=0')
            ->assertSuccessful();

        $fresh = $payment->fresh();
        $this->assertSame('created', $fresh->provider_create_state);
        $this->assertSame(1016, $fresh->provider_total_payment);
        Http::assertSentCount(1);

        $this->artisan('klikqris:backfill-in-flight --chunk=100')
            ->expectsOutputToContain('recovered=0 skipped=0 failed=0')
            ->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_mismatched_status_response_creates_no_reconciliation_event_provider_status_or_fulfillment(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment(['merchant_ref' => 'KLIK-EXPECTED']);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment, [
            'order_id' => 'KLIK-DIFFERENT',
            'status' => 'SUCCESS',
        ]), 200));

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('processed=0 skipped=0 failed=1')
            ->assertExitCode(1);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $payment->order->fresh()->order_status);
        $this->assertSame(0, PaymentEvent::query()->where('payment_id', $payment->id)->where('event_type', 'reconciliation')->count());
        $this->assertFalse(PaymentEvent::query()->where('payment_id', $payment->id)->where('provider_status', 'SUCCESS')->exists());
        Queue::assertNothingPushed();
    }

    public function test_disabled_or_production_reconcile_command_is_inert_and_successful(): void
    {
        config()->set('services.klikqris.sandbox_enabled', false);

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('klikqris:reconcile skipped safely: KlikQRIS is disabled for this environment.')
            ->assertExitCode(0);

        $this->app['env'] = 'production';
        config()->set('app.env', 'production');
        config()->set('services.klikqris.sandbox_enabled', true);

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('klikqris:reconcile skipped safely: KlikQRIS is disabled for this environment.')
            ->assertExitCode(0);
    }

    public function test_enabled_but_misconfigured_local_reconcile_command_still_fails(): void
    {
        config()->set('services.klikqris.sandbox_enabled', true);
        config()->set('services.klikqris.sandbox_api_key', null);

        $this->artisan('klikqris:reconcile --chunk=100')
            ->expectsOutputToContain('klikqris:reconcile failed: active KlikQRIS environment is not ready.')
            ->assertExitCode(1);
    }

    public function test_command_validates_bounded_chunk_option(): void
    {
        $this->artisan('klikqris:reconcile --chunk=0')->assertExitCode(1);
        $this->artisan('klikqris:reconcile --chunk=1001')->assertExitCode(1);
        $this->artisan('klikqris:reconcile --max-payments=0')->assertExitCode(1);
        $this->artisan('klikqris:reconcile --max-payments=101')->assertExitCode(1);
    }

    public function test_schedule_registers_one_minute_klikqris_reconcile_without_overlap(): void
    {
        $schedule = file_get_contents(base_path('routes/console.php'));

        $this->assertTrue(app(KlikQrisProviderReadiness::class)->sandboxSchedulingEnabled());
        $this->assertStringContainsString('klikqris:reconcile --chunk=100', $schedule);
        $this->assertStringContainsString('withoutOverlapping(ReconcileKlikQrisPayments::schedulerMutexMinutes())', $schedule);
        $this->assertStringContainsString('schedulingEnabledFor', $schedule);
    }

    public function test_schedule_does_not_register_klikqris_reconcile_when_sandbox_cannot_be_enabled(): void
    {
        config()->set('services.klikqris.sandbox_enabled', false);

        Artisan::call('schedule:list');

        $this->assertStringNotContainsString('klikqris:reconcile --chunk=100', Artisan::output());
    }

    private function payableKlikQrisPayment(array $attributes = []): Payment
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(array_replace([
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_payload' => ['data' => [
                'order_id' => $attributes['merchant_ref'] ?? 'KLIK-REF',
                'amount' => 1000,
                'total_amount' => 1016,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_reconcile.png',
                'expired_at' => '2026-07-28T07:20:00+00:00',
            ]],
            'provider_create_state' => 'created',
        ], $attributes));

        $payment->order->update([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => $payment->status,
            'expires_at' => now()->addMinutes(15),
        ]);

        return $payment->fresh('order');
    }
}
