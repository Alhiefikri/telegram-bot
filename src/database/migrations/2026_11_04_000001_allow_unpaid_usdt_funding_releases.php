<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE customer_usdt_funding_reservations DROP CONSTRAINT customer_usdt_funding_reservations_state_check');
        DB::statement("ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_state_check CHECK ((state IN ('reserved','expired_unresolved') AND resolved_at IS NULL AND resolution_kind IS NULL AND settled_entry_id IS NULL) OR (state = 'settled' AND resolved_at IS NOT NULL AND resolution_kind = 'settled' AND settled_entry_id IS NOT NULL) OR (state = 'released' AND resolved_at IS NOT NULL AND resolution_kind IN ('never_exposed','provider_declined','externally_returned','compensated','rejected','expired_unpaid','cancelled_by_customer') AND settled_entry_id IS NULL))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::table('customer_usdt_funding_reservations')->whereIn('resolution_kind', ['expired_unpaid', 'cancelled_by_customer'])->exists()) {
            throw new RuntimeException('Cannot roll back while cancelled or expired unpaid USDT funding reservations exist.');
        }

        DB::statement('ALTER TABLE customer_usdt_funding_reservations DROP CONSTRAINT customer_usdt_funding_reservations_state_check');
        DB::statement("ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_state_check CHECK ((state IN ('reserved','expired_unresolved') AND resolved_at IS NULL AND resolution_kind IS NULL AND settled_entry_id IS NULL) OR (state = 'settled' AND resolved_at IS NOT NULL AND resolution_kind = 'settled' AND settled_entry_id IS NOT NULL) OR (state = 'released' AND resolved_at IS NOT NULL AND resolution_kind IN ('never_exposed','provider_declined','externally_returned','compensated','rejected') AND settled_entry_id IS NULL))");
    }
};
