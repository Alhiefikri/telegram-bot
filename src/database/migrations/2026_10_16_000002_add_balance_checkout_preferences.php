<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('balance_payment_preference', 16)->default('idr_first');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('balance_checkout_snapshot')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_balance_payment_preference_check CHECK (balance_payment_preference IN ('idr_only', 'usd_only', 'idr_first', 'usd_first'))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_balance_checkout_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.balance_checkout_snapshot::jsonb IS DISTINCT FROM OLD.balance_checkout_snapshot::jsonb THEN
        RAISE EXCEPTION 'balance_checkout_snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER orders_balance_checkout_snapshot_immutable BEFORE UPDATE ON orders
FOR EACH ROW EXECUTE FUNCTION guard_balance_checkout_snapshot();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_balance_checkout_snapshot_immutable ON orders; DROP FUNCTION IF EXISTS guard_balance_checkout_snapshot();');
            DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_balance_payment_preference_check');
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('balance_checkout_snapshot'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('balance_payment_preference'));
    }
};
