<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_price_policies DROP CONSTRAINT supplier_price_policy_guard');
        }
        Schema::table('supplier_price_policies', fn (Blueprint $table) => $table->dropColumn('buffer_idr'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_price_policies ADD CONSTRAINT supplier_price_policy_guard CHECK (mode IN ('manual', 'percent', 'fixed', 'hybrid') AND markup_basis_points BETWEEN 0 AND 100000 AND profit_idr >= 0 AND minimum_profit_idr >= 0 AND rounding_idr IN (1, 100, 500, 1000) AND version > 0)");
        }
        DB::table('supplier_price_policies')->increment('version', 1, ['updated_at' => now()]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_price_policies DROP CONSTRAINT supplier_price_policy_guard');
        }
        Schema::table('supplier_price_policies', fn (Blueprint $table) => $table->unsignedInteger('buffer_idr')->default(0));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_price_policies ADD CONSTRAINT supplier_price_policy_guard CHECK (mode IN ('manual', 'percent', 'fixed', 'hybrid') AND markup_basis_points BETWEEN 0 AND 100000 AND profit_idr >= 0 AND minimum_profit_idr >= 0 AND buffer_idr >= 0 AND rounding_idr IN (1, 100, 500, 1000) AND version > 0)");
        }
        DB::table('supplier_price_policies')->increment('version', 1, ['updated_at' => now()]);
    }
};
