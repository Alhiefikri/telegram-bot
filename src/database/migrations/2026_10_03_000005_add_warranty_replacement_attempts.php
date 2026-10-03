<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_purchases', function (Blueprint $table): void {
            $table->foreignId('warranty_claim_id')->unique()->nullable()->constrained()->restrictOnDelete();
            $table->boolean('warranty_hold_active')->default(false);
            $table->unsignedBigInteger('warranty_reserved_cost_micros')->default(0);
        });
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->timestamp('replacement_next_attempt_at')->nullable();
            $table->string('replacement_last_error', 80)->nullable();
        });
        Schema::table('supplier_account_units', function (Blueprint $table): void {
            $table->foreignId('replaces_unit_id')->unique()->nullable()->constrained('supplier_account_units')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_purchases ADD CONSTRAINT warranty_purchase_hold_guard CHECK (warranty_reserved_cost_micros >= 0 AND (NOT warranty_hold_active OR (warranty_claim_id IS NOT NULL AND warranty_reserved_cost_micros > 0)))');
        }
    }

    public function down(): void
    {
        Schema::table('supplier_account_units', fn (Blueprint $table) => $table->dropConstrainedForeignId('replaces_unit_id'));
        Schema::table('warranty_claims', fn (Blueprint $table) => $table->dropColumn(['replacement_next_attempt_at', 'replacement_last_error']));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_purchases DROP CONSTRAINT warranty_purchase_hold_guard');
        }
        Schema::table('supplier_purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warranty_claim_id');
            $table->dropColumn(['warranty_hold_active', 'warranty_reserved_cost_micros']);
        });
    }
};
