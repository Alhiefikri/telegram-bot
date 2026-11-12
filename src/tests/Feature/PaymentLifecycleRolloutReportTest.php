<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockNotificationEvent;
use App\Models\StockNotificationRecipient;
use App\Models\TelegramOutboundEffect;
use App\Services\PaymentLifecycleRollout;
use App\Services\PaymentLifecycleRolloutReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentLifecycleRolloutReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_legacy_uncertainty_and_blocks_contract_cleanup(): void
    {
        Payment::factory()->create(['provider_create_state' => 'outcome_unknown']);

        $this->artisan('ops:payment-lifecycle-rollout-report --require-contract-safe')
            ->expectsOutputToContain('provider_uncertainty')
            ->assertExitCode(1);
    }

    public function test_it_reports_a_clean_expand_phase_as_contract_safe(): void
    {
        $this->artisan('ops:payment-lifecycle-rollout-report --require-contract-safe')
            ->expectsOutputToContain('contract_safe')
            ->assertSuccessful();
    }

    public function test_it_reports_order_payment_state_mismatches_without_mutating_legacy_rows(): void
    {
        $payment = Payment::factory()->create();
        Order::query()->whereKey($payment->order_id)->update(['payment_status' => 'paid']);

        $this->artisan('ops:payment-lifecycle-rollout-report')
            ->expectsOutputToContain('lifecycle_state_mismatches')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_missing_stock_recipient_beyond_bounded_page_cannot_make_the_report_contract_safe(): void
    {
        $recipients = $this->createStockRecipients(1001);
        $this->materializeStockRecipients($recipients->take(1000));
        $report = app(PaymentLifecycleRolloutReport::class);
        $rollout = app(PaymentLifecycleRollout::class);

        $bounded = $report->summary($rollout);

        $this->assertSame(0, $bounded['unresolved']['notification_claims']['legacy_stock_unmaterialized']);
        $this->assertSame(0, $bounded['unresolved_total']);
        $this->assertFalse($bounded['unresolved_total_is_exact']);
        $this->assertFalse($bounded['legacy_stock_coverage']['complete']);
        $this->assertSame(1000, $bounded['legacy_stock_coverage']['candidates_scanned']);
        $this->assertSame('bounded', $bounded['legacy_stock_coverage']['mode']);
        $this->assertFalse($bounded['contract_safe']);
        $this->artisan('ops:payment-lifecycle-rollout-report --require-contract-safe')
            ->expectsOutputToContain('incomplete legacy-stock coverage')
            ->assertExitCode(1);

        $exact = $report->summary($rollout, exactLegacyStock: true);

        $this->assertSame(1, $exact['unresolved']['notification_claims']['legacy_stock_unmaterialized']);
        $this->assertSame(1, $exact['unresolved_total']);
        $this->assertTrue($exact['unresolved_total_is_exact']);
        $this->assertTrue($exact['legacy_stock_coverage']['complete']);
        $this->assertSame('exact', $exact['legacy_stock_coverage']['mode']);
        $this->assertFalse($exact['contract_safe']);
        $this->artisan('ops:payment-lifecycle-rollout-report --exact-legacy-stock --require-contract-safe')
            ->assertExitCode(1);
        $this->assertDatabaseCount('stock_notification_recipients', 1001);
        $this->assertDatabaseCount('telegram_outbound_effects', 1000);

        $this->materializeStockRecipients($recipients->slice(1000));

        $this->assertFalse($report->summary($rollout)['contract_safe']);
        $this->artisan('ops:payment-lifecycle-rollout-report --exact-legacy-stock --require-contract-safe')
            ->assertSuccessful();
    }

    public function test_small_complete_stock_observations_keep_exact_counts_and_contract_safety(): void
    {
        $recipients = $this->createStockRecipients(3);
        $this->materializeStockRecipients($recipients->take(2));
        $report = app(PaymentLifecycleRolloutReport::class);
        $rollout = app(PaymentLifecycleRollout::class);

        $summary = $report->summary($rollout);

        $this->assertSame(1, $summary['unresolved']['notification_claims']['legacy_stock_unmaterialized']);
        $this->assertSame(1, $summary['unresolved_total']);
        $this->assertTrue($summary['unresolved_total_is_exact']);
        $this->assertTrue($summary['legacy_stock_coverage']['complete']);
        $this->assertSame(3, $summary['legacy_stock_coverage']['candidates_scanned']);
        $this->assertFalse($summary['contract_safe']);
        $this->assertDatabaseCount('telegram_outbound_effects', 2);
        $this->assertDatabaseCount('stock_notification_recipients', 3);

        $this->materializeStockRecipients($recipients->slice(2));
        $clean = $report->summary($rollout);

        $this->assertSame(0, $clean['unresolved_total']);
        $this->assertTrue($clean['unresolved_total_is_exact']);
        $this->assertTrue($clean['contract_safe']);
        $this->artisan('ops:payment-lifecycle-rollout-report --require-contract-safe')
            ->assertSuccessful();
    }

    public function test_canary_selection_is_stable_and_rollback_never_selects_the_hardened_path(): void
    {
        $payment = Payment::factory()->create();
        config()->set('services.payment_lifecycle_rollout.mode', 'canary');
        config()->set('services.payment_lifecycle_rollout.canary_percentage', 50);

        $rollout = app(PaymentLifecycleRollout::class);
        $this->assertSame($rollout->usesHardenedPath($payment), $rollout->usesHardenedPath($payment));

        config()->set('services.payment_lifecycle_rollout.mode', 'rollback');
        $this->assertFalse($rollout->usesHardenedPath($payment));
    }

    /** @return Collection<int, StockNotificationRecipient> */
    private function createStockRecipients(int $count): Collection
    {
        $event = StockNotificationEvent::create([
            'inventory_movement_id' => InventoryMovement::factory()->create(['order_id' => null])->id,
            'kind' => 'adjustment',
        ]);
        $event->recipients()->insert(array_map(static fn (int $index): array => [
            'stock_notification_event_id' => $event->id,
            'recipient_type' => 'customer',
            'telegram_id' => (string) (990000 + $index),
            'text' => 'Stock is available',
        ], range(1, $count)));

        return $event->recipients()->orderBy('id')->get();
    }

    /** @param Collection<int, StockNotificationRecipient> $recipients */
    private function materializeStockRecipients(Collection $recipients): void
    {
        $rows = $recipients->map(static fn (StockNotificationRecipient $recipient): array => TelegramOutboundEffect::factory()->make([
            'id' => (string) Str::uuid(),
            'source_type' => StockNotificationRecipient::class,
            'source_id' => (string) $recipient->id,
            'effect_type' => TelegramOutboundEffectType::StockNotificationRecipient->value,
            'state' => TelegramOutboundEffectState::Sent,
        ])->getAttributes()
        )->all();

        foreach (array_chunk($rows, 100) as $chunk) {
            TelegramOutboundEffect::query()->insert($chunk);
        }
    }
}
