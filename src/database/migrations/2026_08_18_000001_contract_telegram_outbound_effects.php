<?php

use App\Services\Telegram\TelegramOutboundEffectContractReport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $report = app(TelegramOutboundEffectContractReport::class)->report();
        if (! $report['preflight_ready']) {
            throw new RuntimeException(
                'Telegram outbound effect contract is blocked. Run php artisan telegram:outbound-contract --json and resolve every missing or unsafe effect before migrating.',
            );
        }

        foreach (TelegramOutboundEffectContractReport::LEGACY_INDEXES as $index => $table) {
            $this->dropIndexIfPresent($table, $index);
        }

        foreach (TelegramOutboundEffectContractReport::LEGACY_CLAIM_COLUMNS as $table => $columns) {
            $this->dropColumnsIfPresent($table, $columns);
        }
    }

    public function down(): void
    {
        throw new LogicException(
            'The Telegram outbound effect contract is a point of no return. Restore from a backup or deploy the ledger contract; never reset ambiguous effects to pending.',
        );
    }

    private function dropIndexIfPresent(string $table, string $index): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = collect(Schema::getIndexes($table))
            ->contains(fn (array $definition): bool => ($definition['name'] ?? null) === $index);
        if (! $present) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    /** @param list<string> $columns */
    private function dropColumnsIfPresent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present): void {
            $blueprint->dropColumn($present);
        });
    }
};
