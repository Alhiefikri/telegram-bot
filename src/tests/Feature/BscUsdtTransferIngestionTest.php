<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\BscUsdtTransferIngestion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\TestCase;

#[Group('postgres-release')]
final class BscUsdtTransferIngestionTest extends TestCase
{
    private BscUsdtRailRpcFixture $rpc;

    private CustomerUsdtDepositReceiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $this->receiver = $registry->manualVerify($registry->register(Customer::factory()->create(), BscUsdtRailRpcFixture::ADDRESS, $owner)->receiver, true, $owner);
        $this->rpc = new BscUsdtRailRpcFixture;
        $this->rpc->logs = [$this->rpc->log()];
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);
    }

    public function test_block_pages_resume_from_durable_checkpoint_and_do_not_duplicate_logs(): void
    {
        config(['services.bsc.log_scan_chunk_blocks' => 2]);
        $first = $this->scan();
        $this->assertSame('page_budget_reached', $first['reason']);
        $this->assertSame(103, $first['last_block']);
        $this->assertSame(2, $first['pages']);
        $second = $this->scan();
        $this->assertSame('complete', $second['status']);
        $this->assertSame(105, $second['last_block']);
        $third = $this->scan();
        $this->assertSame(0, $third['pages']);
        $this->assertSame(1, DB::table('usdt_bsc_transfer_observations')->count());
        $observation = DB::table('usdt_bsc_transfer_observations')->sole();
        $this->assertSame(BscUsdtRailRpcFixture::TX, $observation->tx_hash);
        $this->assertSame(0, (int) $observation->log_index);
        $this->assertSame(BscUsdtRailRpcFixture::blockHash(100), $observation->block_hash);
        $this->assertNoMoney();
    }

    public function test_rpc_budget_exhaustion_does_not_commit_an_incomplete_page(): void
    {
        $result = app(BscUsdtTransferIngestion::class)->scan($this->receiver->getKey(), 100, 2, 7);
        $this->assertSame('request_budget_reached', $result['reason']);
        $this->assertSame(7, $result['requests']);
        $this->assertSame(0, $result['pages']);
        $this->assertNull($this->checkpoint()->last_block);
        $this->assertSame(0, DB::table('usdt_bsc_transfer_observations')->count());
        $this->assertSame('complete', $this->scan()['status']);
    }

    public function test_malformed_log_page_is_not_silently_skipped_or_checkpointed(): void
    {
        $log = $this->rpc->log();
        unset($log['logIndex']);
        $this->rpc->onLogs = fn () => [$log];
        $result = $this->scan();
        $this->assertSame('log_page_identity_mismatch', $result['reason']);
        $this->assertNull($this->checkpoint()->last_block);
        $this->assertNoMoney();
    }

    public function test_oversized_page_and_unavailable_rpc_preserve_checkpoint(): void
    {
        $this->rpc->onLogs = fn () => array_fill(0, 101, $this->rpc->log());
        $this->assertSame('log_page_malformed_or_oversized', $this->scan()['reason']);
        $this->assertNull($this->checkpoint()->last_block);
        $this->rpc->onLogs = null;
        $this->rpc->failMethods = ['transferLogs'];
        $this->assertSame('rpc_unavailable', $this->scan()['reason']);
        $this->assertNull($this->checkpoint()->last_block);
        $this->rpc->failMethods = [];
        $this->assertSame('complete', $this->scan()['status']);
    }

    public function test_checkpoint_reorg_latches_review_and_keeps_original_evidence(): void
    {
        $this->scan();
        $original = $this->checkpoint();
        $changed = $this->rpc->blockByNumber(105);
        $changed['hash'] = '0x'.str_repeat('1', 64);
        $this->rpc->blockOverrides[105] = $changed;
        // Move finality beyond the changed old checkpoint so it is the anchor,
        // not the current finalized-tag consistency check, that finds the reorg.
        $this->rpc->finalized = 106;
        $result = $this->scan();
        $this->assertSame('checkpoint_reorg', $result['reason']);
        $this->assertSame($original->last_block_hash, $this->checkpoint()->last_block_hash);
        $this->assertSame('checkpoint_reorg', $this->checkpoint()->review_reason);
        $calls = count($this->rpc->calls);
        $this->assertSame('review', $this->scan()['status']);
        $this->assertSame($calls, count($this->rpc->calls));
        $this->assertSame(1, DB::table('usdt_bsc_transfer_observations')->count());
        $this->assertNoMoney();
    }

    public function test_reorg_during_page_fetch_does_not_publish_logs_or_progress(): void
    {
        $this->rpc->onLogs = function () {
            $block = $this->rpc->blockByNumber(105);
            $block['hash'] = '0x'.str_repeat('1', 64);
            $this->rpc->blockOverrides[105] = $block;

            return [$this->rpc->log()];
        };
        $result = $this->scan();
        $this->assertSame('page_boundary_reorg', $result['reason']);
        $this->assertNull($this->checkpoint()->last_block);
        $this->assertSame('page_boundary_reorg', $this->checkpoint()->review_reason);
        $this->assertSame(0, DB::table('usdt_bsc_transfer_observations')->count());
    }

    public function test_another_scanner_winning_the_version_prevents_stale_page_commit(): void
    {
        $winner = [];
        $this->rpc->onLogs = function () use (&$winner) {
            $this->rpc->onLogs = null;
            $winner = $this->scan();

            return [$this->rpc->log()];
        };
        $loser = $this->scan();
        $this->assertSame('complete', $winner['status']);
        $this->assertSame('stale_scan_version', $loser['reason']);
        $this->assertSame(1, (int) $this->checkpoint()->version);
        $this->assertSame(105, (int) $this->checkpoint()->last_block);
        $this->assertSame(1, DB::table('usdt_bsc_transfer_observations')->count());
    }

    public function test_retired_receiver_continues_discovery_for_late_money(): void
    {
        $owner = User::query()->where('role', UserRole::Owner->value)->firstOrFail();
        app(UsdtDepositReceiverRegistry::class)->retire($this->receiver, $owner, 'Sandbox rotation');
        $this->assertSame('complete', $this->scan()['status']);
        $this->assertSame(1, DB::table('usdt_bsc_transfer_observations')->count());
        $this->assertNoMoney();
    }

    public function test_missing_or_unfinal_rpc_does_not_scan_logs(): void
    {
        $this->rpc->blockOverrides['finalized'] = null;
        $this->assertSame('finality_unavailable', $this->scan()['reason']);
        $this->assertNotContains('transferLogs', $this->rpc->calls);
        $this->assertNull($this->checkpoint()->last_block);
    }

    public function test_first_block_cannot_be_rewritten_on_restart(): void
    {
        $this->scan();
        $this->expectException(InvalidArgumentException::class);
        app(BscUsdtTransferIngestion::class)->scan($this->receiver->getKey(), 101);
    }

    public function test_runtime_command_enrolls_and_rediscovers_checkpoint_without_money_effect(): void
    {
        $this->artisan('usdt-bsc:scan', ['--receiver' => (string) $this->receiver->getKey(), '--from-block' => '100', '--json' => true])->assertSuccessful();
        $this->artisan('usdt-bsc:scan', ['--json' => true])->assertSuccessful();
        $this->assertSame(105, (int) $this->checkpoint()->last_block);
        $this->assertSame(1, DB::table('usdt_bsc_transfer_observations')->count());
        $this->assertNoMoney();
    }

    public function test_runtime_command_rejects_unbounded_options_and_uninitialized_enrollment(): void
    {
        $this->artisan('usdt-bsc:scan', ['--requests' => '101'])->assertExitCode(2);
        $this->artisan('usdt-bsc:scan', ['--receiver' => (string) $this->receiver->getKey()])->assertExitCode(2);
        $this->assertSame([], $this->rpc->calls);
    }

    #[Group('concurrency')]
    public function test_recovery_rotates_receivers_when_budgeted_ticks_share_one_second(): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->firstOrFail();
        $registry = app(UsdtDepositReceiverRegistry::class);
        $receivers = [$this->receiver];
        for ($i = 1; $i < 12; $i++) {
            $receiver = $registry->register(Customer::factory()->create(),
                '0x'.str_pad(dechex($i), 40, '0', STR_PAD_LEFT), $owner)->receiver;
            $receivers[] = $registry->manualVerify($receiver, true, $owner);
        }
        foreach ($receivers as $receiver) {
            DB::table('usdt_bsc_scan_checkpoints')->insert([
                'receiver_id' => $receiver->id, 'first_block' => 100, 'version' => 0, 'updated_at' => now(),
            ]);
        }
        $second = now()->startOfSecond()->toImmutable();
        // An outage already visited every receiver in the same timestamp
        // bucket, so none has the NULL-first advantage on recovery.
        DB::table('usdt_bsc_scan_checkpoints')->update(['last_attempt_at' => $second]);
        for ($tick = 0; $tick < 6; $tick++) {
            $this->travelTo($second->addMicroseconds(($tick + 1) * 100));
            $this->rpc->calls = [];
            Artisan::call('usdt-funding:recover', ['--limit' => 25, '--json' => true]);
            $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertLessThanOrEqual(32, $report['scan_requests']);
            $this->assertLessThanOrEqual(32, count($this->rpc->calls));
        }
        $this->assertSame(12, DB::table('usdt_bsc_scan_checkpoints')->where('last_block', 105)->count(),
            'A shared timestamp must not repeatedly send the request budget to the first receivers.');
        $this->assertNoMoney();
    }

    private function scan(): array
    {
        return app(BscUsdtTransferIngestion::class)->scan($this->receiver->getKey(), 100);
    }

    private function checkpoint(): object
    {
        return DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $this->receiver->getKey())->first();
    }

    private function assertNoMoney(): void
    {
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }
}
