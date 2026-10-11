<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Models\Customer;
use App\Models\CustomerAuthToken;
use App\Models\CustomerLoginLink;
use App\Services\Customer\CustomerAuthMailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerAuthMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_includes_exact_expiry_and_consumption_but_preserves_live_challenges(): void
    {
        $this->freezeTime();
        $live = $this->token();
        $exhausted = $this->token(['purpose' => CustomerAuthTokenPurpose::EmailOtp, 'attempts' => 5]);
        $consumed = $this->token(['consumed_at' => now()]);
        $expired = $this->token(['expires_at' => now()->subSecond()]);
        $boundary = $this->token(['expires_at' => now()]);
        $liveLink = $this->link();
        $usedLink = $this->link(['used_at' => now()]);
        $expiredLink = $this->link(['expires_at' => now()->subSecond()]);
        $boundaryLink = $this->link(['expires_at' => now()]);

        $this->artisan('customer-auth:cleanup')->assertSuccessful();

        foreach ([$live, $exhausted] as $token) {
            $this->assertDatabaseHas('customer_auth_tokens', ['id' => $token->id]);
        }
        foreach ([$consumed, $expired, $boundary] as $token) {
            $this->assertDatabaseMissing('customer_auth_tokens', ['id' => $token->id]);
        }
        $this->assertDatabaseHas('customer_login_links', ['id' => $liveLink->id]);
        foreach ([$usedLink, $expiredLink, $boundaryLink] as $link) {
            $this->assertDatabaseMissing('customer_login_links', ['id' => $link->id]);
        }
    }

    public function test_cleanup_preserves_every_retained_work_status_and_both_foreign_keys(): void
    {
        $this->freezeTime();
        foreach (['pending', 'sent', 'cancelled', 'failed'] as $status) {
            $token = $this->token(['consumed_at' => now()]);
            $link = $this->link(['used_at' => now()]);
            $this->work('auth_token_id', $token->id, $status);
            $this->work('login_link_id', $link->id, $status);
        }
        $this->artisan('customer-auth:cleanup')->assertSuccessful();
        $this->assertDatabaseCount('customer_auth_tokens', 4);
        $this->assertDatabaseCount('customer_login_links', 4);
        $this->assertDatabaseCount('customer_auth_mail_works', 8);
    }

    public function test_cleanup_is_one_bounded_page_per_table_and_does_not_starve_unreferenced_rows(): void
    {
        $this->freezeTime();
        $retained = $this->token(['expires_at' => now()]);
        $this->work('auth_token_id', $retained->id);
        for ($i = 0; $i < 3; $i++) {
            $this->token(['expires_at' => now()]);
            $this->link(['expires_at' => now()]);
        }
        $this->artisan('customer-auth:cleanup', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('customer_auth_tokens', 3);
        $this->assertDatabaseCount('customer_login_links', 2);
        $this->assertDatabaseCount('customer_auth_mail_works', 1);
        $this->artisan('customer-auth:cleanup', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('customer_auth_tokens', 2);
        $this->assertDatabaseCount('customer_login_links', 1);
    }

    public function test_invalid_cleanup_limits_fail_without_deletion(): void
    {
        $this->token(['consumed_at' => now()]);
        foreach (['0', '-1', '1001', 'bad'] as $limit) {
            $this->artisan('customer-auth:cleanup', ['--limit' => $limit])->assertFailed();
        }
        $this->assertDatabaseCount('customer_auth_tokens', 1);
    }

    public function test_legacy_operator_command_is_bounded_and_does_not_delete_shared_tokens_or_work(): void
    {
        $this->freezeTime();
        $this->token(['consumed_at' => now()]);
        $retained = $this->link(['used_at' => now()]);
        $this->work('login_link_id', $retained->id);
        $this->link(['expires_at' => now()]);
        $this->link(['used_at' => now()]);
        $this->artisan('customers:cleanup-login-links', ['--chunk' => 1])->assertSuccessful();
        $this->assertDatabaseCount('customer_login_links', 2);
        $this->assertDatabaseCount('customer_auth_tokens', 1);
        $this->assertDatabaseCount('customer_auth_mail_works', 1);
        $this->artisan('customers:cleanup-login-links', ['--chunk' => 1001])->assertFailed();
        $this->assertDatabaseCount('customer_login_links', 2);
    }

    public function test_recovery_retention_finishes_before_cleanup_removes_expired_parents(): void
    {
        $this->freezeTime();
        Queue::fake();
        $token = $this->token(['expires_at' => now()]);
        $link = $this->link(['expires_at' => now()]);
        $this->work('auth_token_id', $token->id, expiresAt: now());
        $this->work('login_link_id', $link->id, expiresAt: now());
        app(CustomerAuthMailDeliveryService::class)->recover(10);
        $this->assertSame(2, DB::table('customer_auth_mail_works')->where('status', 'cancelled')->whereNull('payload')->count());
        $this->artisan('customer-auth:cleanup')->assertSuccessful();
        $this->assertDatabaseCount('customer_auth_tokens', 1);
        $this->assertDatabaseCount('customer_login_links', 1);
        $this->travel(25)->hours();
        app(CustomerAuthMailDeliveryService::class)->recover(10);
        $this->artisan('customer-auth:cleanup')->assertSuccessful();
        $this->assertDatabaseCount('customer_auth_mail_works', 0);
        $this->assertDatabaseCount('customer_auth_tokens', 0);
        $this->assertDatabaseCount('customer_login_links', 0);
    }

    public function test_health_reports_bounded_counts_without_reading_ciphertext_or_claiming_delivery(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 3; $i++) {
            $this->work('auth_token_id', $this->token()->id);
        }
        $this->work('auth_token_id', $this->token()->id, 'failed');
        $this->work('login_link_id', $this->link()->id, 'sent');
        $this->assertSame(1, Artisan::call('customer-auth:mail-health', ['--limit' => 2]));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['count' => 2, 'capped' => true], $report['counts']['due']);
        $this->assertSame(['count' => 1, 'capped' => false], $report['counts']['failed']);
        $this->assertSame(['count' => 1, 'capped' => false], $report['counts']['sent']);
        $this->assertSame('unverified', $report['provider_delivery']);
        $this->assertStringNotContainsString('invalid-ciphertext-private-material', $output);
        $this->assertStringNotContainsString('private@example.test', $output);
    }

    public function test_health_flags_expired_pending_work_and_invalid_limits(): void
    {
        $this->freezeTime();
        $this->work('auth_token_id', $this->token()->id, expiresAt: now());
        $this->artisan('customer-auth:mail-health')->assertFailed();
        foreach (['0', '-1', '10001', 'bad'] as $limit) {
            $this->artisan('customer-auth:mail-health', ['--limit' => $limit])->assertFailed();
        }
    }

    private function token(array $overrides = []): CustomerAuthToken
    {
        $customer = Customer::factory()->create(['email' => null]);
        $token = (new CustomerAuthToken)->forceFill([
            'customer_id' => $customer->id,
            'email' => 'private@example.test',
            'purpose' => CustomerAuthTokenPurpose::PasswordReset,
            'token_hash' => hash('sha256', random_bytes(32)),
            'challenge_hash' => hash('sha256', random_bytes(32)),
            'session_binding_hash' => hash('sha256', random_bytes(32)),
            'auth_version' => 0,
            'enrollment_version' => 0,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now()->subMinutes(5),
            ...$overrides,
        ]);
        $token->save();

        return $token;
    }

    private function link(array $overrides = []): CustomerLoginLink
    {
        $link = new CustomerLoginLink;
        $link->forceFill([
            'email' => 'private@example.test',
            'token_hash' => hash('sha256', random_bytes(32)),
            'expires_at' => now()->addMinutes(15),
            ...$overrides,
        ])->save();

        return $link;
    }

    private function work(string $foreignKey, int $id, string $status = 'pending', mixed $expiresAt = null): int
    {
        return DB::table('customer_auth_mail_works')->insertGetId([
            $foreignKey => $id,
            'payload' => $status === 'pending' ? 'invalid-ciphertext-private-material' : null,
            'status' => $status,
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
