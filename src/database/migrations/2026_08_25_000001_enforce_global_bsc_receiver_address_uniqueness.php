<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_INDEX = 'bsc_receivers_active_address_unique';

    private const GLOBAL_INDEX = 'bsc_receivers_address_unique';

    public function up(): void
    {
        if (! Schema::hasTable('bsc_payment_receivers')) {
            return;
        }

        $duplicateAddress = DB::table('bsc_payment_receivers')
            ->select('address_normalized')
            ->groupBy('address_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->value('address_normalized');

        if ($duplicateAddress !== null) {
            throw new RuntimeException(
                'Cannot enforce BSC Receiving Address uniqueness while duplicate normalized addresses exist: '
                .(string) $duplicateAddress.'. Resolve the duplicate receiver records before migrating.',
            );
        }

        $this->dropLegacyIndex();
        $this->createGlobalIndex();
    }

    public function down(): void
    {
        if (! Schema::hasTable('bsc_payment_receivers')) {
            return;
        }

        $this->dropGlobalIndex();
        $this->createLegacyIndex();
    }

    private function dropLegacyIndex(): void
    {
        if (! Schema::hasIndex('bsc_payment_receivers', self::LEGACY_INDEX)) {
            return;
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::LEGACY_INDEX);

            return;
        }

        Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
            $table->dropUnique(self::LEGACY_INDEX);
        });
    }

    private function createGlobalIndex(): void
    {
        if (Schema::hasIndex('bsc_payment_receivers', self::GLOBAL_INDEX, 'unique')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::GLOBAL_INDEX
                .' ON bsc_payment_receivers (address_normalized)',
            );

            return;
        }

        Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
            $table->unique('address_normalized', self::GLOBAL_INDEX);
        });
    }

    private function dropGlobalIndex(): void
    {
        if (! Schema::hasIndex('bsc_payment_receivers', self::GLOBAL_INDEX)) {
            return;
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::GLOBAL_INDEX);

            return;
        }

        Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
            $table->dropUnique(self::GLOBAL_INDEX);
        });
    }

    private function createLegacyIndex(): void
    {
        if (Schema::hasIndex('bsc_payment_receivers', self::LEGACY_INDEX)) {
            return;
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $predicate = DB::getDriverName() === 'pgsql' ? 'is_active IS TRUE' : 'is_active = 1';
            DB::statement(
                'CREATE UNIQUE INDEX '.self::LEGACY_INDEX
                .' ON bsc_payment_receivers (address_normalized) WHERE '.$predicate,
            );

            return;
        }

        Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
            $table->unique('address_normalized', self::LEGACY_INDEX);
        });
    }
};
