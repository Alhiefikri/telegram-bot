<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Telegram\Retention\RetentionWindow;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationRetentionDryRunTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'retention-test-');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->database]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        unlink($this->database);
        parent::tearDown();
    }

    private function effect(string $state, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('telegram_outbound_effects')->insert(array_replace([
            'id' => $id, 'effect_key' => 'SECRET-CREDENTIAL-'.$id,
            'effect_group_key' => 'SECRET-GROUP-'.$id, 'effect_type' => 'admin.supplier_health',
            'source_type' => 'App\\Models\\SupplierHealthEvent', 'source_id' => (string) Str::uuid(),
            'telegram_chat_id' => 'SECRET-CHAT-123', 'telegram_method' => 'sendMessage',
            'state' => $state, 'attempts' => 0, 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
            'sent_at' => $state === 'sent' ? '2026-01-01 12:00:00' : null,
            'failed_at' => $state === 'failed' ? '2026-01-01 12:00:00' : null,
            'last_error' => 'SECRET-PAYLOAD', 'outcome_metadata' => '{"message":"SECRET-MESSAGE"}',
        ], $extra));

        return $id;
    }

    private function runScan(array $options = [], bool $json = true): array
    {
        $exit = Artisan::call('telegram:notification-retention-dry-run', array_replace([
            '--family' => 'effects', '--policy-version' => RetentionWindow::POLICY,
            '--as-of' => '2026-10-28T12:00:00+07:00', '--timezone' => 'Asia/Jakarta', '--horizon' => '90',
            '--json' => $json,
        ], $options));
        $output = Artisan::output();
        $this->assertStringNotContainsString('SECRET', $output);

        return [$json ? json_decode($output, true, flags: JSON_THROW_ON_ERROR) : $output, $exit];
    }

    public function test_states_and_exhausted_failure_remain_protected_without_touching_database(): void
    {
        $this->effect('pending', ['next_attempt_at' => '2027-01-01 12:00:00']);
        $this->effect('sending', ['lease_until' => '2026-01-02 12:00:00']);
        $this->effect('unknown');
        $this->effect('manual_review');
        $this->effect('failed');
        $this->effect('failed', ['attempts' => 999]);
        $this->effect('sent');
        $this->effect('sent', ['effect_type' => 'receipt.credentials']);
        $this->effect('NEW-STATE', ['effect_type' => 'new.family']);
        $before = hash_file('sha256', $this->database);
        [$report, $exit] = $this->runScan();
        $this->assertSame(0, $exit);
        $this->assertSame(9, $report['protected']);
        $this->assertSame(0, $report['archive_review_candidates']);
        $this->assertSame(0, $report['delete_candidates']);
        foreach (['active_obligation', 'ambiguity', 'manual_review', 'retryable_failure', 'future_due', 'financial_or_credential_history', 'unsupported_family', 'unsupported_state', 'archive_unverified', 'hold_unproven'] as $reason) {
            $this->assertGreaterThan(0, $report['reason_counts'][$reason]);
        }
        $this->assertSame($before, hash_file('sha256', $this->database));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        [$text, $textExit] = $this->runScan([], false);
        $this->assertSame(0, $textExit);
        $this->assertStringContainsString('delete_candidates: 0', $text);
    }

    public function test_stable_resume_visits_each_root_once_and_binds_window(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->effect('sent');
        }
        $total = 0;
        $after = null;
        $token = null;
        do {
            $options = ['--roots' => '2', '--page' => '2', '--operator-artifact' => true];
            if ($token !== null) {
                $options['--resume'] = $token;
            }
            [$report, $exit] = $this->runScan($options);
            $this->assertContains($exit, [0, 2]);
            $total += $report['roots_evaluated'];
            $artifact = $report['operator_artifact'];
            $this->assertSame($after, $artifact['cursor_before']);
            $after = $artifact['cursor_after'];
            $token = $artifact['resume'];
        } while ($report['partial']);
        $this->assertSame(7, $total);
        [, $invalid] = $this->runScan(['--resume' => $token, '--horizon' => '30']);
        $this->assertSame(1, $invalid);
    }

    public function test_dependency_truncation_is_partial_not_a_candidate_and_details_are_capped(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->effect('sent', ['effect_group_key' => 'SECRET-SHARED']);
        }
        [$report, $exit] = $this->runScan(['--per-root' => '4', '--dependencies' => '5']);
        $this->assertSame(2, $exit);
        $this->assertTrue($report['partial']);
        $this->assertLessThanOrEqual(4, $report['dependency_rows']);
        $this->assertSame(1, $report['reason_counts']['dependency_budget_exceeded']);
        $this->assertSame(0, $report['archive_review_candidates']);
        [$report] = $this->runScan(['--details' => '3']);
        $this->assertLessThanOrEqual(3, count($report['details']));
    }

    public function test_all_source_roots_are_reported_and_supplier_discriminator_is_unproven(): void
    {
        DB::table('supplier_health_events')->insert(['id' => (string) Str::uuid(), 'message' => 'SECRET-MESSAGE', 'created_at' => '2026-01-01 12:00:00']);
        foreach (array_keys(RetentionWindow::TABLES) as $family) {
            [$report, $exit] = $this->runScan(['--family' => $family]);
            $this->assertSame(0, $exit, $family);
            if ($family === 'supplier-health') {
                $this->assertSame(1, $report['reason_counts']['unclassified_supplier_event']);
                $this->assertSame(1, $report['reason_counts']['unresolved_supplier_episode']);
                $this->assertSame(1, $report['reason_counts']['missing_effect']);
            }
        }
    }

    public function test_incoming_and_outgoing_lineage_protect_sent_sibling(): void
    {
        $unknown = $this->effect('unknown');
        $sent = $this->effect('sent', ['supersedes_effect_id' => $unknown]);
        [$report, $exit] = $this->runScan(['--after' => $sent, '--stop' => $sent]);
        $this->assertSame(0, $exit);
        [$report] = $this->runScan();
        $this->assertSame(2, $report['reason_counts']['ambiguity']);
        $this->assertSame(2, $report['protected']);
    }

    public function test_age_scenario_uses_latest_activity_and_exact_cutoff_but_never_authorizes_archive(): void
    {
        $this->effect('sent', ['updated_at' => '2026-07-30 12:00:00', 'sent_at' => '2026-07-30 12:00:00']);
        $this->effect('sent', ['updated_at' => '2026-07-30 12:00:01', 'sent_at' => '2026-07-30 12:00:01']);
        $this->effect('sent', ['updated_at' => null]);
        $this->effect('sent', ['sent_at' => '2025-01-01 00:00:00']);
        [$report] = $this->runScan();
        $buckets = array_count_values(array_column($report['details'], 'age_bucket'));
        $this->assertSame(['at_or_beyond_scenario' => 1, 'too_recent' => 1, 'unproven' => 2], array_replace(['at_or_beyond_scenario' => 0, 'too_recent' => 0, 'unproven' => 0], $buckets));
        $this->assertSame(4, $report['reason_counts']['age_unproven']);
        $this->assertSame(0, $report['archive_review_candidates']);
    }

    public function test_invalid_inputs_and_query_failure_never_print_payload_or_exception(): void
    {
        foreach ([['--roots' => '0'], ['--seconds' => '11'], ['--family' => 'SECRET-table'], ['--after' => 'SECRET-binding'], ['--as-of' => '2026-02-31T12:00:00+07:00'], ['--timezone' => 'UTC'], ['--policy-version' => 'wrong']] as $options) {
            [$report, $exit] = $this->runScan($options);
            $this->assertSame(1, $exit);
            $this->assertSame('invalid_input', $report['error_code']);
        }
        DB::statement('DROP TABLE telegram_outbound_effects');
        [$report, $exit] = $this->runScan(['--operator-artifact' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('database_or_runtime_error', $report['error_code']);
        $this->assertNull($report['operator_artifact']['cursor_after']);
    }

    public function test_event_closure_includes_missing_recipients_store_channel_and_partial_audience(): void
    {
        $product = Product::factory()->create();
        $customer = Customer::factory()->create();
        $movement = InventoryMovement::factory()->create(['product_id' => $product->id]);
        $stock = DB::table('stock_notification_events')->insertGetId([
            'inventory_movement_id' => $movement->id, 'kind' => 'restocked',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        DB::table('stock_notification_recipients')->insert([
            'stock_notification_event_id' => $stock, 'customer_id' => $customer->id,
            'telegram_id' => 'SECRET-CHAT', 'text' => 'SECRET-MESSAGE',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        $price = DB::table('product_price_change_events')->insertGetId([
            'event_key' => (string) Str::uuid(), 'product_id' => $product->id, 'product_name' => 'SECRET-PRODUCT',
            'old_price_amount' => 100, 'new_price_amount' => 90,
            'recipients_materialized_at' => '2026-01-01 12:00:00',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        DB::table('product_price_change_notification_recipients')->insert([
            'event_id' => $price, 'customer_id' => $customer->id, 'telegram_id' => 'SECRET-CHAT',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        $this->effect('sent', [
            'effect_type' => 'price_change.store_channel', 'source_type' => 'App\\Models\\ProductPriceChangeEvent',
            'source_id' => (string) $price,
        ]);
        $broadcast = DB::table('telegram_broadcasts')->insertGetId([
            'broadcast_key' => (string) Str::uuid(), 'message' => 'SECRET-MESSAGE', 'status' => 'completed',
            'recipients_materialized_at' => '2026-01-01 12:00:00', 'completed_at' => '2026-01-01 12:00:00',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        foreach (['stock-events', 'stock-recipients', 'price-events', 'price-recipients', 'broadcasts'] as $family) {
            [$report, $exit] = $this->runScan(['--family' => $family]);
            $this->assertSame(0, $exit, $family);
            $this->assertSame(1, $report['protected'], $family);
            $this->assertArrayHasKey('replay_possible', $report['reason_counts']);
            if (str_starts_with($family, 'stock-')) {
                $this->assertArrayHasKey('partial_audience', $report['reason_counts']);
                $this->assertArrayHasKey('missing_effect', $report['reason_counts']);
            } elseif (str_starts_with($family, 'price-')) {
                $this->assertArrayHasKey('projection_incomplete', $report['reason_counts']);
                $this->assertArrayHasKey('missing_effect', $report['reason_counts']);
                $this->assertArrayHasKey('active_obligation', $report['reason_counts']);
            } else {
                $this->assertArrayHasKey('empty_audience', $report['reason_counts']);
            }
        }
        DB::table('telegram_broadcast_recipients')->insert([
            'broadcast_id' => $broadcast, 'customer_id' => $customer->id, 'telegram_id' => 'SECRET-CHAT',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        [$report, $exit] = $this->runScan(['--family' => 'broadcast-recipients']);
        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('missing_effect', $report['reason_counts']);
    }

    public function test_dry_run_preserves_dedupe_manual_review_and_strict_receipt_ordering(): void
    {
        $first = $this->effect('unknown', [
            'effect_type' => 'receipt.credentials', 'effect_group_key' => 'SECRET-RECEIPT-GROUP',
            'sequence' => 1, 'template_version' => 'receipt-photo-caption-v1',
        ]);
        $next = $this->effect('pending', [
            'effect_type' => 'receipt.credentials', 'effect_group_key' => 'SECRET-RECEIPT-GROUP',
            'sequence' => 2,
        ]);
        $this->runScan();
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $this->assertNull($ledger->claim($first));
        $this->assertNull($ledger->claim($next));
        $resolved = $ledger->finalizeOperatorSent($first, '123456');
        $this->assertSame(TelegramOutboundEffectState::Sent, $resolved->state);
        $this->assertNotNull($ledger->claim($next));
        $this->assertSame(2, DB::table('telegram_outbound_effects')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
