<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerAuthContractPostgresTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer auth');
        if (! Schema::hasTable('customer_auth_tokens')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
    }

    public function test_fresh_migrations_reinstall_ownership_guards_when_postgres_functions_survive(): void
    {
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $this->assertRejected(fn () => DB::table('customer_identities')->insert([
            'customer_id' => $other->id,
            'provider' => 'telegram',
            'provider_subject' => (string) $owner->telegram_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_competing_customers_cannot_own_one_provider_subject(): void
    {
        $customers = [Customer::factory()->create()->id, Customer::factory()->create()->id];
        $subject = bin2hex(random_bytes(16));
        $results = $this->race(array_map(fn ($id) => fn () => $this->insertIdentity($id, $subject), $customers));
        sort($results);
        $this->assertSame(['conflict', 'linked'], $results);
        $this->assertSame(1, DB::table('customer_identities')->where('provider_subject', $subject)->count());
    }

    public function test_competing_subjects_cannot_duplicate_one_customers_provider(): void
    {
        $customer = Customer::factory()->create();
        $results = $this->race([
            fn () => $this->insertIdentity($customer->id, bin2hex(random_bytes(16))),
            fn () => $this->insertIdentity($customer->id, bin2hex(random_bytes(16))),
        ]);
        sort($results);
        $this->assertSame(['conflict', 'linked'], $results);
        $this->assertSame(1, DB::table('customer_identities')->where('customer_id', $customer->id)->count());
    }

    public function test_telegram_identity_cannot_disagree_with_canonical_customer_or_be_reassigned(): void
    {
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $attributes = ['customer_id' => $owner->id, 'provider' => 'telegram', 'provider_subject' => (string) $owner->telegram_id, 'created_at' => now(), 'updated_at' => now()];
        $this->assertRejected(fn () => DB::table('customer_identities')->insert([...$attributes, 'customer_id' => $other->id]));
        $id = DB::table('customer_identities')->insertGetId($attributes);
        $this->assertRejected(fn () => DB::table('customer_identities')->where('id', $id)->update(['customer_id' => $other->id]));
        $this->assertRejected(fn () => DB::table('customers')->where('id', $owner->id)->update(['telegram_id' => null]));
        $this->assertRejected(fn () => DB::table('customers')->where('id', $owner->id)->delete());
        $this->assertSame((string) $owner->telegram_id, DB::table('customer_identities')->where('id', $id)->value('provider_subject'));
    }

    public function test_database_rejects_invalid_purpose_hash_expiry_attempts_and_binding_rewrites(): void
    {
        $customer = Customer::factory()->create();
        $base = ['customer_id' => $customer->id, 'purpose' => 'password_reset', 'token_hash' => hash('sha256', random_bytes(32)), 'created_at' => now()->startOfSecond(), 'updated_at' => now()->startOfSecond(), 'expires_at' => now()->startOfSecond()->addMinutes(30)];
        foreach ([['purpose' => 'magic_link'], ['token_hash' => 'plaintext-secret'], ['expires_at' => $base['created_at']], ['expires_at' => now()->startOfSecond()->addMinutes(31)], ['attempts' => -1], ['attempts' => 1], ['customer_id' => null], ['purpose' => 'email_otp'], ['purpose' => 'telegram_link']] as $invalid) {
            $this->assertRejected(fn () => DB::table('customer_auth_tokens')->insert([...$base, ...$invalid]));
        }
        $id = DB::table('customer_auth_tokens')->insertGetId($base);
        $this->assertRejected(fn () => DB::table('customer_auth_tokens')->insert($base));
        $this->assertRejected(fn () => DB::table('customer_auth_tokens')->where('id', $id)->update(['purpose' => 'email_verification']));
        DB::table('customer_auth_tokens')->where('id', $id)->update(['consumed_at' => now()]);
        $this->assertRejected(fn () => DB::table('customer_auth_tokens')->where('id', $id)->update(['consumed_at' => null]));
        $otp = [...$base, 'purpose' => 'email_otp', 'email' => 'buyer@example.test', 'token_hash' => hash_hmac('sha256', '000123', 'disposable-key'), 'challenge_hash' => hash('sha256', random_bytes(32)), 'expires_at' => $base['created_at']->copy()->addMinutes(10)];
        $otpId = DB::table('customer_auth_tokens')->insertGetId($otp);
        DB::table('customer_auth_tokens')->where('id', $otpId)->update(['attempts' => 5]);
        $this->assertRejected(fn () => DB::table('customer_auth_tokens')->where('id', $otpId)->update(['attempts' => 6]));
        $this->assertRejected(fn () => DB::table('customer_auth_tokens')->where('id', $otpId)->update(['attempts' => 0]));
        $this->assertSame(5, DB::table('customer_auth_tokens')->where('id', $otpId)->value('attempts'));
    }

    public function test_anonymous_telegram_challenge_keeps_its_browser_binding_without_creating_customer(): void
    {
        $binding = hash('sha256', random_bytes(32));
        $id = DB::table('customer_auth_tokens')->insertGetId([
            'purpose' => 'telegram_link',
            'token_hash' => hash('sha256', random_bytes(32)),
            'session_binding_hash' => $binding,
            'created_at' => now()->startOfSecond(),
            'updated_at' => now()->startOfSecond(),
            'expires_at' => now()->startOfSecond()->addMinutes(5),
        ]);
        $row = DB::table('customer_auth_tokens')->find($id);
        $this->assertNull($row->customer_id);
        $this->assertNull($row->email);
        $this->assertSame($binding, $row->session_binding_hash);
        $this->assertRejected(fn () => DB::table('customer_auth_tokens')->where('id', $id)->update(['session_binding_hash' => hash('sha256', random_bytes(32))]));
    }

    public function test_additive_migration_preserves_existing_customer_and_active_magic_link(): void
    {
        $migration = require database_path('migrations/2026_10_11_100000_add_customer_authentication_contract.php');
        $migration->down();
        try {
            $id = DB::table('customers')->insertGetId(['first_name' => 'Legacy', 'telegram_id' => null, 'email' => null, 'created_at' => now(), 'updated_at' => now()]);
            $linkId = DB::table('customer_login_links')->insertGetId(['email' => 'legacy@example.test', 'token_hash' => hash('sha256', 'legacy-active-link'), 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();
            $customer = Customer::findOrFail($id);
            $this->assertNull($customer->email);
            $this->assertNull($customer->password);
            $this->assertSame(0, $customer->auth_version);
            $this->assertNull(DB::table('customer_login_links')->where('id', $linkId)->value('used_at'));
            $this->assertSame(hash('sha256', 'legacy-active-link'), DB::table('customer_login_links')->where('id', $linkId)->value('token_hash'));
        } finally {
            if (! Schema::hasTable('customer_auth_tokens')) {
                $migration->up();
            }
        }
    }

    private function insertIdentity(int $customerId, string $subject): string
    {
        try {
            DB::table('customer_identities')->insert(['customer_id' => $customerId, 'provider' => 'google', 'provider_subject' => $subject, 'created_at' => now(), 'updated_at' => now()]);

            return 'linked';
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }

            return 'conflict';
        }
    }

    private function assertRejected(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Database accepted an invalid auth contract mutation.');
        } catch (QueryException $error) {
            $this->assertContains($error->getCode(), ['23505', '23514', '23503']);
        }
    }
}
