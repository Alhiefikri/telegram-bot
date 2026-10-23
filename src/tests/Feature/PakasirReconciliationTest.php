<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PakasirReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.pakasir.slug' => 'local', 'services.pakasir.api_key' => 'synthetic']);
    }

    public function test_backlog_is_bounded_and_cursor_survives_command_restart_then_wraps(): void
    {
        $payments = Payment::factory()->count(5)->create(['provider' => 'pakasir', 'status' => PaymentStatus::Pending]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request['order_id'];

            return Http::response(['transaction' => ['project' => 'local', 'order_id' => $request['order_id'], 'amount' => $request['amount'], 'status' => 'pending']]);
        });

        foreach ([2, 4, 5] as $count) {
            $this->assertSame(0, Artisan::call('pakasir:reconcile', ['--chunk' => 2, '--max-payments' => 2]));
            $this->assertCount($count, $seen);
        }
        $this->assertEqualsCanonicalizing($payments->pluck('merchant_ref')->all(), $seen);
        Artisan::call('pakasir:reconcile', ['--max-payments' => 2]);
        $this->assertCount(7, $seen);
        $this->assertSame($seen[0], $seen[5]);
        Cache::flush();
        Artisan::call('pakasir:reconcile', ['--max-payments' => 1]);
        $this->assertSame($seen[0], $seen[7]);
        $this->assertSame(5, Payment::where('status', PaymentStatus::Pending)->count());
    }

    public function test_outage_request_cap_counts_retries_and_still_advances_past_failed_rows(): void
    {
        Payment::factory()->count(3)->create(['provider' => 'pakasir', 'status' => PaymentStatus::Pending]);
        config(['services.pakasir.transaction_detail_retry_delay_ms' => 0]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertSame(1, Artisan::call('pakasir:reconcile', ['--max-requests' => 2]));
        Http::assertSentCount(2);
        $this->assertStringContainsString('stop_reason=request_limit', Artisan::output());
        $this->assertSame(3, Payment::where('status', PaymentStatus::Pending)->count());
        $firstReference = Http::recorded()[0][0]['order_id'];
        $this->assertSame(1, Artisan::call('pakasir:reconcile', ['--max-requests' => 1]));
        Http::assertSentCount(3);
        $this->assertNotSame($firstReference, Http::recorded()[2][0]['order_id']);
    }

    public function test_deadline_cannot_start_a_fresh_retry_budget_or_another_payment(): void
    {
        Payment::factory()->count(3)->create(['provider' => 'pakasir', 'status' => PaymentStatus::Pending]);
        config(['services.pakasir.reconcile_max_execution_seconds' => 1, 'services.pakasir.transaction_detail_retry_delay_ms' => 1000]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertSame(1, Artisan::call('pakasir:reconcile'));
        Http::assertSentCount(1);
        $this->assertStringContainsString('stop_reason=deadline', Artisan::output());
        $this->assertSame(3, Payment::where('status', PaymentStatus::Pending)->count());
        $this->assertNull(ProviderVerificationProfiles::deadline());
        config(['services.pakasir.transaction_detail_retry_delay_ms' => 0]);
        Artisan::call('pakasir:reconcile', ['--max-requests' => 1]);
        Http::assertSentCount(2);
    }

    public function test_request_delayed_before_transport_is_not_sent_after_deadline(): void
    {
        Payment::factory()->create(['provider' => 'pakasir', 'status' => PaymentStatus::Pending]);
        config(['services.pakasir.reconcile_max_execution_seconds' => 1]);
        Http::fake(['*' => Http::response([], 503)]);
        Http::globalRequestMiddleware(function ($request) {
            usleep(1_050_000);

            return $request;
        });
        $this->assertSame(1, Artisan::call('pakasir:reconcile'));
        Http::assertNothingSent();
        $this->assertStringContainsString('stop_reason=deadline', Artisan::output());
    }
}
