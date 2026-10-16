<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerApiCredential;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerApiCredentialsPostgresTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer API credentials');
        if (! Schema::hasTable('customer_api_credentials')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
    }

    public function test_three_process_generate_and_confirmed_rotation_each_have_one_winner(): void
    {
        $customer = Customer::factory()->create();
        $results = $this->race(array_fill(0, 3, function () use ($customer): string {
            try {
                app(CustomerApiCredentials::class)->generate($customer);

                return 'issued';
            } catch (CustomerApiFailure $failure) {
                return $failure->machineCode;
            }
        }));
        sort($results);
        $this->assertSame(['issued', 'key_exists', 'key_exists'], $results);
        $confirmation = app(CustomerApiCredentials::class)->prepareRotation($customer, 100);
        $results = $this->race(array_fill(0, 3, function () use ($customer, $confirmation): string {
            try {
                app(CustomerApiCredentials::class)->rotate($customer, 100, $confirmation);

                return 'rotated';
            } catch (CustomerApiFailure $failure) {
                return $failure->machineCode;
            }
        }));
        sort($results);
        $this->assertSame(['rotated', 'stale_key_action', 'stale_key_action'], $results);
        $this->assertSame(1, CustomerApiCredential::query()->where('customer_id', $customer->id)->whereNull('revoked_at')->count());
        $this->assertSame(2, CustomerApiCredential::query()->where('customer_id', $customer->id)->count());
    }

    public function test_revalidated_work_holds_revocation_until_commit_then_later_mutations_are_rejected(): void
    {
        $customer = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $token = $service->generate($customer)->takeSecret();
        $principal = $service->authenticate('Bearer '.$token);
        $directory = $this->createWorkspace('api-revocation');
        try {
            $results = $this->race([
                function () use ($principal, $directory): string {
                    DB::transaction(function () use ($principal, $directory): void {
                        $principal->revalidateForMutation();
                        touch($directory.'/validated');
                        $this->waitForMarker($directory.'/revoking');
                        // The revoker has begun but cannot commit while this shared key lock is held.
                        usleep(200000);
                        $this->assertNull(DB::table('customer_api_credentials')->where('id', $principal->credentialId)->value('revoked_at'));
                    });

                    return 'finished';
                },
                function () use ($customer, $directory): string {
                    $this->waitForMarker($directory.'/validated');
                    touch($directory.'/revoking');
                    app(CustomerApiCredentials::class)->disable($customer);

                    return 'revoked';
                },
            ]);
            $this->assertSame(['finished', 'revoked'], $results);
        } finally {
            $this->cleanupWorkspace($directory);
        }
        $this->assertNull($service->authenticate('Bearer '.$token));
        $this->expectException(CustomerApiFailure::class);
        DB::transaction(fn () => $principal->revalidateForMutation());
    }

    public function test_database_independently_rejects_second_active_key_reactivation_and_identity_rebinding(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $service->generate($customer);
        $id = CustomerApiCredential::query()->where('customer_id', $customer->id)->value('id');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_api_credentials')->insert([
            'customer_id' => $customer->id, 'selector' => bin2hex(random_bytes(16)), 'secret_digest' => str_repeat('a', 64), 'created_at' => now(),
        ]), 'customer_api_one_active');
        $service->disable($customer);
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_api_credentials')->where('id', $id)->update(['revoked_at' => null]), 'Customer API credential binding is immutable');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_api_credentials')->where('id', $id)->update(['customer_id' => $other->id]), 'Customer API credential binding is immutable');
    }
}
