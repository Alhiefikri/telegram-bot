<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Couples `state = 'accepted'` with a bound `order_id` in the existing
 * `customer_api_order_claim_shape` check, so no writer — in or outside the
 * application — can store an accepted claim without its Order. Until now the
 * shape check accepted any `state`/`order_id` combination and the guard trigger
 * only froze identity and acceptance; it did not require the Order.
 *
 * Databases created before this migration already carry the uncoupled shape, so
 * the constraint is replaced here. A database created by the current create
 * migration already receives the coupled definition and this is a no-op.
 */
return new class extends Migration
{
    private const TABLE = 'customer_api_order_claims';

    private const CONSTRAINT = 'customer_api_order_claim_shape';

    /** The historical shape: state, preference, source and quantity bounds only. */
    private const SHAPE = "state IN ('in_progress', 'accepted') AND preference IN ('idr_only', 'usd_only', 'idr_first', 'usd_first') AND preference_source IN ('account', 'override') AND quantity > 0 AND quantity <= 100";

    /**
     * The acceptance coupling, in the canonical form PostgreSQL renders for
     * `(state = 'accepted'::text) = (order_id IS NOT NULL)`, so the idempotency
     * probe below matches the stored definition exactly.
     */
    private const COUPLING = "(state = 'accepted'::text) = (order_id IS NOT NULL)";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceShape(self::SHAPE.' AND '.self::COUPLING);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceShape(self::SHAPE);
    }

    /**
     * Adds or replaces the shape check only when its definition differs, so the
     * migration is safe on a fresh database that already has the target shape
     * and on a database that predates the coupling.
     */
    private function replaceShape(string $definition): void
    {
        $constraint = self::CONSTRAINT;
        $table = self::TABLE;

        $current = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [$constraint, $table],
        );

        if ($current !== null && str_contains((string) $current->definition, $definition)) {
            return;
        }

        if ($current !== null) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$definition})");
    }
};
