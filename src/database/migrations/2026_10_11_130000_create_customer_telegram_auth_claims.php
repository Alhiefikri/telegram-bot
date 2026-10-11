<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_telegram_auth_claims', function (Blueprint $table): void {
            $table->id();
            $table->enum('operation', ['login', 'link']);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('auth_version')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            $table->string('session_binding_hash', 64);
            $table->string('capability_hash', 64)->unique();
            $table->string('actor_id', 19)->nullable();
            $table->foreignId('proof_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->unsignedBigInteger('proof_auth_version')->nullable();
            $table->string('confirmation_hash', 64)->nullable()->unique();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->enum('failure', ['conflict', 'invalid'])->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement(<<<'SQL'
ALTER TABLE customer_telegram_auth_claims ADD CONSTRAINT customer_telegram_claim_contract CHECK (
    session_binding_hash ~ '^[0-9a-f]{64}$' AND capability_hash ~ '^[0-9a-f]{64}$'
    AND (confirmation_hash IS NULL OR confirmation_hash ~ '^[0-9a-f]{64}$')
    AND ((operation = 'login' AND customer_id IS NULL AND auth_version IS NULL AND authenticated_at IS NULL)
        OR (operation = 'link' AND customer_id IS NOT NULL AND auth_version IS NOT NULL AND auth_version >= 0 AND authenticated_at IS NOT NULL))
    AND (actor_id IS NULL OR actor_id ~ '^[1-9][0-9]{0,18}$')
    AND (proof_customer_id IS NULL) = (proof_auth_version IS NULL)
    AND (proof_auth_version IS NULL OR proof_auth_version >= 0)
    AND (prepared_at IS NULL) = (confirmation_hash IS NULL)
    AND (prepared_at IS NULL OR (actor_id IS NOT NULL AND proof_customer_id IS NOT NULL AND prepared_at >= created_at AND prepared_at < expires_at))
    AND (confirmed_at IS NULL OR (prepared_at IS NOT NULL AND confirmed_at >= prepared_at AND confirmed_at < expires_at))
    AND (consumed_at IS NULL OR (confirmed_at IS NOT NULL AND consumed_at >= confirmed_at AND consumed_at < expires_at))
    AND expires_at > created_at AND expires_at <= created_at + interval '5 minutes'
)
SQL);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION preserve_customer_telegram_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.operation, NEW.customer_id, NEW.auth_version, NEW.authenticated_at, NEW.session_binding_hash,
        NEW.capability_hash, NEW.expires_at, NEW.created_at) IS DISTINCT FROM
       (OLD.operation, OLD.customer_id, OLD.auth_version, OLD.authenticated_at, OLD.session_binding_hash,
        OLD.capability_hash, OLD.expires_at, OLD.created_at)
       OR (OLD.prepared_at IS NOT NULL AND
           (NEW.actor_id, NEW.proof_customer_id, NEW.proof_auth_version, NEW.prepared_at)
           IS DISTINCT FROM
           (OLD.actor_id, OLD.proof_customer_id, OLD.proof_auth_version, OLD.prepared_at))
       OR (OLD.confirmed_at IS NOT NULL AND NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at)
       OR (OLD.confirmed_at IS NOT NULL AND NEW.confirmation_hash IS DISTINCT FROM OLD.confirmation_hash)
       OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at)
       OR (OLD.failure IS NOT NULL AND NEW.failure IS DISTINCT FROM OLD.failure) THEN
        RAISE EXCEPTION 'Telegram claim binding or terminal state is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER customer_telegram_claim_binding BEFORE UPDATE ON customer_telegram_auth_claims
FOR EACH ROW EXECUTE FUNCTION preserve_customer_telegram_claim();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_telegram_auth_claims');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS preserve_customer_telegram_claim();');
        }
    }
};
