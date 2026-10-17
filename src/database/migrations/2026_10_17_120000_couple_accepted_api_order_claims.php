<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'customer_api_order_claims';

    private const CONSTRAINT = 'customer_api_order_claim_shape';

    /** The historical shape: state, preference, source and quantity bounds only. */
    private const SHAPE = "state IN ('in_progress', 'accepted') AND preference IN ('idr_only', 'usd_only', 'idr_first', 'usd_first') AND preference_source IN ('account', 'override') AND quantity > 0 AND quantity <= 100";

    private const COUPLING = "((state = 'accepted') = (order_id IS NOT NULL))";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE customer_api_order_claims IN ACCESS EXCLUSIVE MODE');
            $invalid = DB::table(self::TABLE)->whereRaw('NOT '.self::COUPLING)->exists();
            if ($invalid) {
                throw new RuntimeException('Customer API order claims have inconsistent acceptance/order bindings. Reconcile claim history before retrying this migration; no claims were changed.');
            }
            $this->replaceShape(self::SHAPE.' AND '.self::COUPLING);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(fn () => $this->replaceShape(self::SHAPE));
    }

    private function replaceShape(string $definition): void
    {
        $constraint = self::CONSTRAINT;
        $table = self::TABLE;

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$definition})");
    }
};
