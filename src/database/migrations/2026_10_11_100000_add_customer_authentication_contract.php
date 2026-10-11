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
            $table->string('password')->nullable();
            $table->unsignedBigInteger('auth_version')->default(0);
            $table->unsignedBigInteger('enrollment_version')->default(0);
        });

        Schema::create('customer_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->enum('provider', ['google', 'telegram']);
            $table->string('provider_subject');
            $table->string('provider_email')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_subject']);
            $table->unique(['customer_id', 'provider']);
        });

        Schema::create('customer_auth_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('email')->nullable();
            $table->enum('purpose', ['email_verification', 'email_otp', 'password_reset', 'telegram_link']);
            $table->string('token_hash', 64)->unique();
            $table->string('challenge_hash', 64)->nullable()->unique();
            $table->string('session_binding_hash', 64)->nullable();
            $table->string('proposed_password_hash')->nullable();
            $table->unsignedBigInteger('auth_version')->nullable();
            $table->unsignedBigInteger('enrollment_version')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->index(['customer_id', 'purpose', 'consumed_at']);
            $table->index(['email', 'purpose', 'consumed_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_auth_versions_check CHECK (auth_version >= 0 AND enrollment_version >= 0)');
        DB::statement("ALTER TABLE customer_identities ADD CONSTRAINT customer_identity_subject_check CHECK (length(provider_subject) > 0 AND (provider <> 'telegram' OR provider_subject ~ '^[1-9][0-9]{0,18}$'))");
        DB::statement(<<<'SQL'
ALTER TABLE customer_auth_tokens ADD CONSTRAINT customer_auth_token_contract_check CHECK (
    (customer_id IS NOT NULL OR email IS NOT NULL OR (purpose = 'telegram_link' AND session_binding_hash IS NOT NULL))
    AND (email IS NULL OR (email = lower(trim(email)) AND length(email) > 0))
    AND token_hash ~ '^[0-9a-f]{64}$'
    AND (challenge_hash IS NULL OR challenge_hash ~ '^[0-9a-f]{64}$')
    AND (session_binding_hash IS NULL OR session_binding_hash ~ '^[0-9a-f]{64}$')
    AND (auth_version IS NULL OR auth_version >= 0)
    AND (enrollment_version IS NULL OR enrollment_version >= 0)
    AND attempts >= 0 AND attempts <= CASE WHEN purpose = 'email_otp' THEN 5 ELSE 0 END
    AND (purpose <> 'email_otp' OR (email IS NOT NULL AND challenge_hash IS NOT NULL))
    AND (purpose <> 'telegram_link' OR session_binding_hash IS NOT NULL)
    AND expires_at > created_at
    AND expires_at <= created_at + CASE purpose
        WHEN 'email_otp' THEN interval '10 minutes'
        WHEN 'telegram_link' THEN interval '5 minutes'
        ELSE interval '30 minutes' END
)
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_customer_identity_contract() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.customer_id, NEW.provider, NEW.provider_subject) IS DISTINCT FROM (OLD.customer_id, OLD.provider, OLD.provider_subject) THEN
        RAISE EXCEPTION 'Customer identity ownership is immutable' USING ERRCODE = '23514';
    END IF;
    IF NEW.provider = 'telegram' THEN
        PERFORM 1 FROM customers WHERE id = NEW.customer_id AND telegram_id::text = NEW.provider_subject FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Telegram canonical ownership conflict' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER customer_identity_contract BEFORE INSERT OR UPDATE ON customer_identities
FOR EACH ROW EXECUTE FUNCTION enforce_customer_identity_contract();

CREATE OR REPLACE FUNCTION preserve_customer_telegram_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.telegram_id IS DISTINCT FROM OLD.telegram_id AND EXISTS (
        SELECT 1 FROM customer_identities WHERE customer_id = OLD.id AND provider = 'telegram'
    ) THEN
        RAISE EXCEPTION 'Telegram canonical ownership is already linked' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER customer_telegram_identity_contract BEFORE UPDATE ON customers
FOR EACH ROW EXECUTE FUNCTION preserve_customer_telegram_identity();

CREATE OR REPLACE FUNCTION preserve_customer_auth_token_binding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.customer_id, NEW.email, NEW.purpose, NEW.token_hash, NEW.challenge_hash, NEW.session_binding_hash,
        NEW.proposed_password_hash, NEW.auth_version, NEW.enrollment_version, NEW.expires_at, NEW.created_at)
        IS DISTINCT FROM
       (OLD.customer_id, OLD.email, OLD.purpose, OLD.token_hash, OLD.challenge_hash, OLD.session_binding_hash,
        OLD.proposed_password_hash, OLD.auth_version, OLD.enrollment_version, OLD.expires_at, OLD.created_at)
       OR NEW.attempts < OLD.attempts
       OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at) THEN
        RAISE EXCEPTION 'Customer auth token binding or terminal state is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER customer_auth_token_binding BEFORE UPDATE ON customer_auth_tokens
FOR EACH ROW EXECUTE FUNCTION preserve_customer_auth_token_binding();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS customer_telegram_identity_contract ON customers; DROP FUNCTION IF EXISTS preserve_customer_telegram_identity();');
        }
        Schema::dropIfExists('customer_auth_tokens');
        Schema::dropIfExists('customer_identities');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_customer_identity_contract(); DROP FUNCTION IF EXISTS preserve_customer_auth_token_binding(); ALTER TABLE customers DROP CONSTRAINT customers_auth_versions_check;');
        }
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['password', 'auth_version', 'enrollment_version']);
        });
    }
};
