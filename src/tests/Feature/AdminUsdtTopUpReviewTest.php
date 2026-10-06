<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUsdtTopUpReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_non_owner_cannot_read_receiver_observations_or_money_review(): void
    {
        $this->get(route('admin.store-balance.usdt-review.index'))->assertRedirect();
        foreach ([UserRole::Admin, UserRole::Finance] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.store-balance.usdt-review.index'))->assertForbidden();
            $this->post(route('admin.store-balance.usdt-review.previews.store'), [])->assertForbidden();
            $this->post(route('admin.store-balance.usdt-review.previews.confirm', (string) Str::uuid()), [])->assertForbidden();
            $this->post(route('admin.store-balance.usdt-review.recovery-previews.store'), [])->assertForbidden();
            $this->post(route('admin.store-balance.usdt-review.recovery-previews.confirm', (string) Str::uuid()), [])->assertForbidden();
        }
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_owner_review_does_not_register_unknown_customers_or_mutate_money(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->get(route('admin.store-balance.usdt-review.index', ['top_up' => 'invalid']))
            ->assertRedirect();
        $this->actingAs($owner)->get(route('admin.store-balance.usdt-review.index'))->assertOk();
        $this->assertSame(0, DB::table('customers')->count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_revoked_owner_cannot_read_review_through_a_stale_authenticated_user(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner);
        DB::table('users')->where('id', $owner->id)->update(['role' => UserRole::Admin->value]);
        $this->get(route('admin.store-balance.usdt-review.index'))->assertForbidden();
        $this->assertSame(0, DB::table('usdt_top_up_review_requests')->count());
        $this->assertSame(0, DB::table('usdt_reorg_recovery_cases')->count());
    }
}
