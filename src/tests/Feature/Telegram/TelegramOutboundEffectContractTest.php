<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramOutboundEffectContractReport;
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

    public function test_strict_contract_command_accepts_a_clean_fresh_schema(): void
    {
        $this->artisan('telegram:outbound-contract', ['--strict' => true])
            ->expectsOutputToContain('telegram outbound contract: ready')
            ->expectsOutputToContain('migration safe: yes')
            ->assertSuccessful();
    }
}
