<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_api_order_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('operation', 32);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->foreignId('credential_id')->nullable()->constrained('customer_api_credentials')->restrictOnDelete();
            $table->string('state', 16);
            $table->string('attempt_token', 32)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('preference', 16);
            $table->string('preference_source', 16);
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('expected_idr_total')->nullable();
            $table->unsignedBigInteger('expected_usd_total_micro')->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            // The durable idempotency guard: never a check-then-insert.
            $table->unique(['customer_id', 'operation', 'idempotency_key'], 'customer_api_order_claim_key');
            $table->unique('order_id');
            $table->index(['state', 'lease_expires_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('api_order_claim_id')->nullable()->after('checkout_key')->constrained('customer_api_order_claims')->restrictOnDelete();
            $table->unique('api_order_claim_id');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->string('origin', 16)->default('customer')->after('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE customer_api_order_claims ADD CONSTRAINT customer_api_order_claim_shape CHECK (state IN ('in_progress', 'accepted') AND preference IN ('idr_only', 'usd_only', 'idr_first', 'usd_first') AND preference_source IN ('account', 'override') AND quantity > 0 AND quantity <= 100)");
            DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_origin_check CHECK (origin IN ('customer', 'api'))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_customer_api_order_claim() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Customer API order claim history is immutable';
    END IF;
    IF NEW.customer_id IS DISTINCT FROM OLD.customer_id
       OR NEW.operation IS DISTINCT FROM OLD.operation
       OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
       OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
       OR NEW.credential_id IS DISTINCT FROM OLD.credential_id
       OR NEW.preference IS DISTINCT FROM OLD.preference
       OR NEW.preference_source IS DISTINCT FROM OLD.preference_source
       OR NEW.product_id IS DISTINCT FROM OLD.product_id
       OR NEW.quantity IS DISTINCT FROM OLD.quantity
       OR NEW.expected_idr_total IS DISTINCT FROM OLD.expected_idr_total
       OR NEW.expected_usd_total_micro IS DISTINCT FROM OLD.expected_usd_total_micro
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Customer API order claim identity is immutable';
    END IF;
    IF OLD.state = 'accepted' AND (NEW.state IS DISTINCT FROM OLD.state OR NEW.order_id IS DISTINCT FROM OLD.order_id) THEN
        RAISE EXCEPTION 'Customer API order claim acceptance is immutable';
    END IF;
    IF OLD.order_id IS NOT NULL AND NEW.order_id IS DISTINCT FROM OLD.order_id THEN
        RAISE EXCEPTION 'Customer API order claim acceptance is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER customer_api_order_claim_guard BEFORE UPDATE OR DELETE ON customer_api_order_claims
FOR EACH ROW EXECUTE FUNCTION guard_customer_api_order_claim();
CREATE OR REPLACE FUNCTION guard_orders_api_origin() RETURNS trigger AS $$
BEGIN
    IF OLD.api_order_claim_id IS NOT NULL AND NEW.api_order_claim_id IS DISTINCT FROM OLD.api_order_claim_id THEN
        RAISE EXCEPTION 'Order API origin is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER orders_api_origin_guard BEFORE UPDATE ON orders
FOR EACH ROW EXECUTE FUNCTION guard_orders_api_origin();
CREATE OR REPLACE FUNCTION guard_cart_origin() RETURNS trigger AS $$
BEGIN
    IF NEW.origin IS DISTINCT FROM OLD.origin THEN
        RAISE EXCEPTION 'Cart origin is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER carts_origin_guard BEFORE UPDATE ON carts
FOR EACH ROW EXECUTE FUNCTION guard_cart_origin();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS carts_origin_guard ON carts');
            DB::statement('DROP TRIGGER IF EXISTS orders_api_origin_guard ON orders');
            DB::statement('DROP TRIGGER IF EXISTS customer_api_order_claim_guard ON customer_api_order_claims');
            DB::statement('DROP FUNCTION IF EXISTS guard_cart_origin()');
            DB::statement('DROP FUNCTION IF EXISTS guard_orders_api_origin()');
            DB::statement('DROP FUNCTION IF EXISTS guard_customer_api_order_claim()');
            DB::statement('ALTER TABLE carts DROP CONSTRAINT IF EXISTS carts_origin_check');
        }
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['api_order_claim_id']);
            $table->dropConstrainedForeignId('api_order_claim_id');
        });
        Schema::dropIfExists('customer_api_order_claims');
    }
};
