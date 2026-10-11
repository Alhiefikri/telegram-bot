<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_google_auth_claims', function (Blueprint $table): void {
            $table->id();
            $table->enum('operation', ['login', 'register', 'link']);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('auth_version')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            $table->string('state_hash', 64)->unique();
            $table->string('session_binding_hash', 64);
            $table->string('configuration_fingerprint', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement(<<<'SQL'
ALTER TABLE customer_google_auth_claims ADD CONSTRAINT customer_google_claim_contract CHECK (
    state_hash ~ '^[0-9a-f]{64}$' AND session_binding_hash ~ '^[0-9a-f]{64}$'
    AND configuration_fingerprint ~ '^[0-9a-f]{64}$'
    AND ((operation IN ('login', 'register') AND customer_id IS NULL AND auth_version IS NULL AND authenticated_at IS NULL)
        OR (operation = 'link' AND customer_id IS NOT NULL AND auth_version IS NOT NULL AND auth_version >= 0
            AND authenticated_at IS NOT NULL AND authenticated_at <= created_at AND authenticated_at > created_at - interval '5 minutes'))
    AND expires_at > created_at AND expires_at <= created_at + interval '5 minutes'
    AND (consumed_at IS NULL OR (consumed_at >= created_at AND consumed_at < expires_at))
)
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION preserve_customer_google_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.operation, NEW.customer_id, NEW.auth_version, NEW.authenticated_at, NEW.state_hash,
        NEW.session_binding_hash, NEW.configuration_fingerprint, NEW.expires_at, NEW.created_at) IS DISTINCT FROM
       (OLD.operation, OLD.customer_id, OLD.auth_version, OLD.authenticated_at, OLD.state_hash,
        OLD.session_binding_hash, OLD.configuration_fingerprint, OLD.expires_at, OLD.created_at)
       OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at) THEN
        RAISE EXCEPTION 'Google claim binding and consumption are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER customer_google_claim_binding BEFORE UPDATE ON customer_google_auth_claims
FOR EACH ROW EXECUTE FUNCTION preserve_customer_google_claim();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_google_auth_claims');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS preserve_customer_google_claim();');
        }
    }
};
