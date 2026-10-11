<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Order;
use App\Services\Customer\CustomerOrderClaimService;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerOrderClaimPostgresTest extends TestCase
{
    use RunsPostgresWorkers;

    private array $customerIds = [];

    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer Order claim');
        if (! Schema::hasTable('customer_identities')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->orderIds !== []) {
                AuditLog::query()->where('action', 'order.customer_claimed')->whereIn('subject_id', $this->orderIds)->delete();
                Order::query()->whereIn('id', $this->orderIds)->delete();
            }
            if ($this->customerIds !== []) {
                CustomerIdentity::query()->whereIn('customer_id', $this->customerIds)->delete();
                Customer::query()->whereIn('id', $this->customerIds)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_competing_email_telegram_and_nullable_google_accounts_produce_one_owner(): void
    {
        $google = $this->customer();
        $this->google($google);
        $email = $this->customer(['email' => 'claim-race@example.test', 'email_verified_at' => now()]);
        $telegram = $this->customer(['telegram_id' => 987654321]);
        $claimants = [$google, $email, $telegram];
        [$order, $key] = $this->order($this->customer());
        $hash = $order->credential_access_key_hash;

        $results = $this->runConcurrentWorkers(3, fn (int $index): string => app(CustomerOrderClaimService::class)->claim($claimants[$index], $order->invoice_number, $key) ? 'claimed' : 'denied', 'customer-claim-race');
        $this->assertWorkersSucceeded($results);
        $outcomes = array_column($results, 'value');
        sort($outcomes);
        $this->assertSame(['claimed', 'denied', 'denied'], $outcomes);
        $winner = $order->fresh()->customer_id;
        $this->assertContains($winner, array_map(fn (Customer $customer): int => $customer->id, $claimants));
        foreach ($claimants as $claimant) {
            $result = app(CustomerOrderClaimService::class)->claim($claimant, $order->invoice_number, $key);
            $claimant->id === $winner ? $this->assertNotNull($result) : $this->assertNull($result);
        }
        $this->assertSame($hash, $order->fresh()->credential_access_key_hash);
        $this->assertTrue(app(StorefrontOrderAccess::class)->verifyKey($order->fresh(), $key));
        $this->assertSame(1, AuditLog::query()->where('action', 'order.customer_claimed')->where('subject_id', $order->id)->count());
    }

    public function test_concurrent_repeated_claims_for_one_customer_are_idempotent(): void
    {
        $customer = $this->customer();
        $this->google($customer);
        [$order, $key] = $this->order($this->customer());
        $results = $this->runConcurrentWorkers(3, fn (): string => app(CustomerOrderClaimService::class)->claim($customer, $order->invoice_number, $key) ? 'claimed' : 'denied', 'customer-claim-replay');
        $this->assertWorkersSucceeded($results);
        $this->assertSame(['claimed', 'claimed', 'claimed'], array_column($results, 'value'));
        $this->assertSame($customer->id, $order->fresh()->customer_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'order.customer_claimed')->where('subject_id', $order->id)->count());
    }

    public function test_owner_enrollment_committing_before_claim_prevents_reassignment(): void
    {
        $owner = $this->customer();
        $claimant = $this->customer(['email' => 'enrollment-race@example.test', 'email_verified_at' => now()]);
        [$order, $key] = $this->order($owner);
        $workspace = $this->createWorkspace('customer-claim-enrollment');
        try {
            $results = $this->runConcurrentWorkers(2, function (int $index) use ($owner, $claimant, $order, $key, $workspace): string {
                if ($index === 0) {
                    return DB::transaction(function () use ($owner, $workspace): string {
                        $locked = Customer::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                        $this->google($locked);
                        touch($workspace.'/enrolling');
                        usleep(150_000);

                        return 'enrolled';
                    });
                }
                $this->waitForMarker($workspace.'/enrolling');

                return app(CustomerOrderClaimService::class)->claim($claimant, $order->invoice_number, $key) ? 'claimed' : 'denied';
            }, 'customer-claim-enrollment-workers');
            $this->assertWorkersSucceeded($results);
            $this->assertSame(['enrolled', 'denied'], array_column($results, 'value'));
            $this->assertSame($owner->id, $order->fresh()->customer_id);
            $this->assertTrue(app(StorefrontOrderAccess::class)->verifyKey($order->fresh(), $key));
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    private function customer(array $attributes = []): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => null, 'email' => null, 'email_verified_at' => null, ...$attributes])->fresh();
        $this->customerIds[] = $customer->id;

        return $customer;
    }

    private function google(Customer $customer): void
    {
        (new CustomerIdentity)->forceFill(['customer_id' => $customer->id, 'provider' => 'google', 'provider_subject' => 'claim-google-'.$customer->id])->save();
    }

    private function order(Customer $owner): array
    {
        $order = Order::factory()->create(['customer_id' => $owner->id, 'payment_status' => PaymentStatus::Paid]);
        $this->orderIds[] = $order->id;

        return [$order, app(StorefrontOrderAccess::class)->issue($order)];
    }
}
