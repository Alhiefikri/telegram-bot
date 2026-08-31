<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectContractReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramOutboundEffectContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_has_applied_the_ledger_only_contract(): void
    {
        foreach (TelegramOutboundEffectContractReport::LEGACY_CLAIM_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    "Legacy Telegram claim column {$table}.{$column} remains after the contract migration.",
                );
            }
        }

        foreach (TelegramOutboundEffectContractReport::LEGACY_INDEXES as $index => $table) {
            $present = collect(Schema::getIndexes($table))
                ->contains(fn (array $definition): bool => ($definition['name'] ?? null) === $index);

            $this->assertFalse($present, "Legacy Telegram recovery index {$table}.{$index} remains after the contract migration.");
        }

        $report = app(TelegramOutboundEffectContractReport::class)->report();

        $this->assertTrue($report['contract_applied']);
        $this->assertTrue($report['preflight_ready']);
        $this->assertTrue($report['contract_ready']);
        $this->assertSame([], $report['source_code_references']);
    }

    public function test_receipt_successor_selection_is_delegated_to_the_ledger_protocol(): void
    {
        $references = collect(app(TelegramOutboundEffectContractReport::class)->report()['source_code_references'])
            ->filter(fn (array $reference): bool => str_starts_with($reference['column'], 'receipt_successor_selector:'))
            ->values()
            ->all();

        $this->assertSame([], $references);
    }

    public function test_strict_contract_command_accepts_a_clean_fresh_schema(): void
    {
        $this->artisan('telegram:outbound-contract', ['--strict' => true])
            ->expectsOutputToContain('telegram outbound contract: ready')
            ->expectsOutputToContain('migration safe: yes')
            ->assertSuccessful();
    }

    public function test_contract_report_counts_a_bsc_checkout_effect_as_migrated(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('manual_checkout_customer_notification_started_at')->nullable();
        });

        $order = Order::factory()->create();
        $order->forceFill(['manual_checkout_customer_notification_started_at' => now()])->save();
        TelegramOutboundEffect::factory()->create([
            'effect_type' => TelegramOutboundEffectType::BscCheckoutInstructions->value,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'state' => TelegramOutboundEffectState::Sent,
        ]);

        $report = app(TelegramOutboundEffectContractReport::class)->report();

        $this->assertSame(0, $report['missing_effects']['checkout_or_order_notifications']);
        $this->assertSame(0, $report['unsafe_effects']['checkout_or_order_notifications']);
    }
}
