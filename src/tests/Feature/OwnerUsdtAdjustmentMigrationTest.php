<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Services\StoreBalance\BalanceAdjustmentService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class OwnerUsdtAdjustmentMigrationTest extends NonTransactionalDatabaseTestCase
{
    public function test_empty_migration_can_roll_back_and_be_applied_again(): void
    {
        $migration = require database_path('migrations/2026_10_07_120000_add_owner_usdt_adjustments.php');

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('balance_adjustment_requests', 'usdt_ledger_entry_id'));
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn('balance_adjustment_requests', 'usdt_ledger_entry_id'));
    }

    public function test_rollback_refuses_to_remove_a_posted_usdt_adjustment(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 901543, 'locale' => 'en']);
        $service = app(BalanceAdjustmentService::class);
        $preview = $service->request($owner, (string) $customer->telegram_id, '12.000001', 'Owner attestation', currency: 'USDT');
        $result = $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $migration = require database_path('migrations/2026_10_07_120000_add_owner_usdt_adjustments.php');

        try {
            $migration->down();
            $this->fail('Expected rollback to refuse existing USDT adjustment history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back owner USDT adjustments', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('balance_adjustment_requests', 'usdt_ledger_entry_id'));
        $this->assertSame($result->entryId, $preview->fresh()->usdt_ledger_entry_id);
        $this->assertSame(12000001, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertDatabaseHas('customer_usdt_balance_accounting_events', [
            'customer_usdt_balance_entry_id' => $result->entryId,
            'amount_micro' => 12000001,
        ]);
    }
}
