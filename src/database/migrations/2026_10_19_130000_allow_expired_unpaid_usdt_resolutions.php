<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The immutable USDT target resolution gains one closed kind: a confirmed
     * target whose payment window and grace elapsed without any settlement.
     * The kind carries no ledger entry, exactly like the other non-monetary
     * resolutions, and is written only together with the slot release.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE customer_usdt_top_up_resolutions DROP CONSTRAINT IF EXISTS usdt_resolution_kind');
        DB::statement("ALTER TABLE customer_usdt_top_up_resolutions ADD CONSTRAINT usdt_resolution_kind CHECK (kind IN ('settled','externally_refunded','resolved_by_compensation','rejected','expired_unpaid','cancelled_by_customer') AND ((kind = 'settled' AND receipt_id IS NOT NULL AND entry_id IS NOT NULL AND evidence_id IS NOT NULL) OR (kind <> 'settled' AND entry_id IS NULL)))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE customer_usdt_top_up_resolutions DROP CONSTRAINT IF EXISTS usdt_resolution_kind');
        DB::statement("ALTER TABLE customer_usdt_top_up_resolutions ADD CONSTRAINT usdt_resolution_kind CHECK (kind IN ('settled','externally_refunded','resolved_by_compensation','rejected') AND ((kind = 'settled' AND receipt_id IS NOT NULL AND entry_id IS NOT NULL AND evidence_id IS NOT NULL) OR (kind <> 'settled' AND entry_id IS NULL)))");
    }
};
