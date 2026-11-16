<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Claim timestamps are stored as Jakarta wall time (config/app.php), without a timezone.
        $this->replaceGuard("CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'");
    }

    public function down(): void
    {
        $this->replaceGuard('CURRENT_TIMESTAMP');
    }

    private function replaceGuard(string $currentTime): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION guard_customer_api_order_claim() RETURNS trigger AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.state = 'in_progress' AND OLD.order_id IS NULL
            AND OLD.created_at < ({$currentTime}) - INTERVAL '24 hours'
            AND (OLD.lease_expires_at IS NULL OR OLD.lease_expires_at < ({$currentTime}))
            AND NOT EXISTS (
                SELECT 1 FROM orders
                WHERE orders.api_order_claim_id = OLD.id
                   OR orders.checkout_key = 'api-' || substr(encode(sha256(convert_to('api-order:' || CAST(OLD.id AS text), 'UTF8')), 'hex'), 1, 60)
            )
        THEN RETURN OLD; END IF;
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
