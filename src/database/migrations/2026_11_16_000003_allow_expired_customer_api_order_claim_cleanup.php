<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_api_order_claims', function (Blueprint $table): void {
            $table->index(['customer_id', 'state', 'created_at'], 'customer_api_claim_retention');
        });
        if (DB::getDriverName() === 'pgsql') {
            $this->replaceGuard(true);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->replaceGuard(false);
        }
        Schema::table('customer_api_order_claims', function (Blueprint $table): void {
            $table->dropIndex('customer_api_claim_retention');
        });
    }

    private function replaceGuard(bool $allowExpired): void
    {
        $delete = $allowExpired
            ? <<<'SQL'
IF OLD.state = 'in_progress' AND OLD.order_id IS NULL
    AND OLD.created_at < CURRENT_TIMESTAMP - INTERVAL '24 hours'
    AND (OLD.lease_expires_at IS NULL OR OLD.lease_expires_at < CURRENT_TIMESTAMP)
    AND NOT EXISTS (
        SELECT 1 FROM orders
        WHERE orders.api_order_claim_id = OLD.id
           OR orders.checkout_key = 'api-' || substr(encode(sha256(convert_to('api-order:' || CAST(OLD.id AS text), 'UTF8')), 'hex'), 1, 60)
    )
THEN RETURN OLD; END IF;
SQL
            : '';
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION guard_customer_api_order_claim() RETURNS trigger AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        {$delete}
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
\$\$ LANGUAGE plpgsql;
SQL);
    }
};
