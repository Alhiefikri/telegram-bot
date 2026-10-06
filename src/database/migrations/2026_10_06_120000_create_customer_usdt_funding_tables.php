<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Customer USDT funding policy schema (#548, work package U3):
 *
 * - customer_usdt_funding_terms: append-only customer-facing terms versions.
 *   A funding target snapshots the version it was confirmed against, so an
 *   Admin edit can never rewrite an existing confirmation or invoice.
 * - customer_usdt_funding_policies: the DB/Admin singleton that carries the
 *   versioned funding policy. H0-v1 (issue #548 overlay) explicitly disables
 *   business min/max/per-customer/aggregate caps and presets: no cap column
 *   exists here, and only representational/overflow guards (strict positive
 *   micro-USDT parsing, bounded confirmation TTL, nonnegative fee) survive.
 *   USDT funding defaults to disabled and no rail readiness provider is
 *   registered, so a missing/disabled policy fail-closes every new target.
 * - customer_usdt_funding_pauses: Owner funding/spending/rail pause rows with
 *   durable state and audit. Pausing blocks new funding targets only; old
 *   deposit reconciliation, settlement and refunds are never gated by it.
 * - customer_usdt_funding_reservations: the durable active-target reservation
 *   (one open target per Customer across rails, enforced by a partial unique
 *   index). Expiry moves a reservation to expired_unresolved and keeps the
 *   slot held; only a real settlement (linked ledger entry) or a typed release
 *   resolution can resolve it. No global bucket/clearing row is locked.
 *
 * Nothing here reads or writes money: reservation rows are exposure/slot
 * evidence, the ledger stays the sole money owner (#546/#547).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_usdt_funding_terms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique('customer_usdt_funding_terms_version_unique');
            $table->text('body');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('customer_usdt_funding_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 64)->unique('customer_usdt_funding_policies_singleton_unique');
            $table->boolean('funding_enabled')->default(false);
            $table->unsignedInteger('confirmation_ttl_seconds')->default(600);
            $table->bigInteger('application_fee_micro')->default(0);
            $table->unsignedInteger('terms_version');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->foreign('terms_version', 'customer_usdt_funding_policies_terms_fk')
                ->references('version')
                ->on('customer_usdt_funding_terms')
                ->restrictOnDelete();
        });

        Schema::create('customer_usdt_funding_pauses', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32)->unique('customer_usdt_funding_pauses_scope_unique');
            $table->string('reason', 191);
            $table->foreignId('paused_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at');
            $table->timestamps();
        });

        Schema::create('customer_usdt_funding_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('source_type', 64);
            $table->string('source_id', 64);
            $table->string('rail', 32);
            $table->bigInteger('amount_micro');
            $table->bigInteger('fee_micro')->default(0);
            $table->unsignedBigInteger('policy_version');
            $table->unsignedInteger('terms_version');
            $table->string('request_fingerprint', 64);
            $table->string('state', 24)->default('reserved');
            $table->timestamp('reserved_at');
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_kind', 24)->nullable();
            $table->string('resolution_reference', 191)->nullable();
            $table->string('resolution_actor', 191)->nullable();
            $table->unsignedBigInteger('settled_entry_id')->nullable();
            $table->timestamps();

            $table->foreign('settled_entry_id', 'customer_usdt_funding_reservations_entry_fk')
                ->references('id')
                ->on('customer_usdt_balance_entries')
                ->restrictOnDelete();
            $table->unique(['source_type', 'source_id'], 'customer_usdt_funding_reservations_source_unique');
            $table->index(['state', 'expires_at', 'id'], 'customer_usdt_funding_reservations_expiry_index');
            $table->index(['customer_id', 'resolved_at'], 'customer_usdt_funding_reservations_customer_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            // One open reservation per Customer across rails; expired but
            // unresolved funds keep resolved_at NULL, so the slot stays held.
            DB::statement(
                'CREATE UNIQUE INDEX customer_usdt_funding_reservations_active_customer_unique '
                .'ON customer_usdt_funding_reservations (customer_id) WHERE resolved_at IS NULL',
            );
            // One ledger entry settles at most one reservation.
            DB::statement(
                'CREATE UNIQUE INDEX customer_usdt_funding_reservations_settled_entry_unique '
                .'ON customer_usdt_funding_reservations (settled_entry_id) WHERE settled_entry_id IS NOT NULL',
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_usdt_funding_policies ADD CONSTRAINT customer_usdt_funding_policies_ttl_check CHECK (confirmation_ttl_seconds BETWEEN 60 AND 3600)');
            DB::statement('ALTER TABLE customer_usdt_funding_policies ADD CONSTRAINT customer_usdt_funding_policies_fee_nonnegative CHECK (application_fee_micro >= 0)');
            DB::statement("ALTER TABLE customer_usdt_funding_pauses ADD CONSTRAINT customer_usdt_funding_pauses_scope_check CHECK (scope IN ('funding', 'spending', 'rail_binance_pay', 'rail_bsc_usdt'))");
            DB::statement("ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_rail_check CHECK (rail IN ('binance_pay', 'bsc_usdt'))");
            DB::statement('ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_amount_positive CHECK (amount_micro > 0)');
            DB::statement('ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_fee_nonnegative CHECK (fee_micro >= 0)');
            DB::statement('ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_expiry_after_reservation CHECK (expires_at > reserved_at)');
            DB::statement(<<<'SQL'
ALTER TABLE customer_usdt_funding_reservations ADD CONSTRAINT customer_usdt_funding_reservations_state_check CHECK (
    (state IN ('reserved', 'expired_unresolved') AND resolved_at IS NULL AND resolution_kind IS NULL AND settled_entry_id IS NULL)
    OR (state = 'settled' AND resolved_at IS NOT NULL AND resolution_kind = 'settled' AND settled_entry_id IS NOT NULL)
    OR (state = 'released' AND resolved_at IS NOT NULL AND resolution_kind IN ('never_exposed', 'provider_declined', 'externally_returned', 'compensated') AND settled_entry_id IS NULL)
)
SQL);
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_customer_usdt_funding_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Customer USDT funding terms and reservation identity are immutable';
END $$;
SQL);
            DB::statement('CREATE TRIGGER customer_usdt_funding_terms_immutable BEFORE UPDATE OR DELETE ON customer_usdt_funding_terms FOR EACH ROW EXECUTE FUNCTION protect_customer_usdt_funding_history()');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_customer_usdt_funding_reservation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Customer USDT funding reservations cannot be deleted';
    END IF;

    IF OLD.resolved_at IS NOT NULL THEN
        RAISE EXCEPTION 'Resolved Customer USDT funding reservations are immutable';
    END IF;

    IF NEW.customer_id IS DISTINCT FROM OLD.customer_id
        OR NEW.source_type IS DISTINCT FROM OLD.source_type
        OR NEW.source_id IS DISTINCT FROM OLD.source_id
        OR NEW.rail IS DISTINCT FROM OLD.rail
        OR NEW.amount_micro IS DISTINCT FROM OLD.amount_micro
        OR NEW.fee_micro IS DISTINCT FROM OLD.fee_micro
        OR NEW.policy_version IS DISTINCT FROM OLD.policy_version
        OR NEW.terms_version IS DISTINCT FROM OLD.terms_version
        OR NEW.request_fingerprint IS DISTINCT FROM OLD.request_fingerprint
        OR NEW.reserved_at IS DISTINCT FROM OLD.reserved_at
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
    THEN
        RAISE EXCEPTION 'Customer USDT funding reservation identity is immutable';
    END IF;

    RETURN NEW;
END $$;
SQL);
            DB::statement('CREATE TRIGGER customer_usdt_funding_reservations_guard BEFORE UPDATE OR DELETE ON customer_usdt_funding_reservations FOR EACH ROW EXECUTE FUNCTION protect_customer_usdt_funding_reservation()');
        }

        $now = now();

        // Version 1 of the approved H0-v1 customer terms for USDT top-ups.
        DB::table('customer_usdt_funding_terms')->insert([
            'version' => 1,
            'body' => <<<'TERMS'
Saldo Rupiah dan USDT merupakan saldo belanja toko yang terpisah. Saldo tidak dapat ditarik atau ditransfer ke user lain dan tidak dikonversi otomatis. Top-up USDT tidak dikenai fee aplikasi toko; biaya transfer Binance/jaringan BSC ditanggung pengirim. Gunakan metode yang dipilih, token USDT yang benar dan nominal pembayaran persis seperti instruksi. Pembayaran tidak cocok tidak dikreditkan otomatis; hubungi support dengan bukti untuk pemeriksaan Owner. Masa konfirmasi nominal 10 menit; deadline pembayaran mengikuti instruksi transaksi. Pembayaran yang masih diperiksa belum menjadi saldo tersedia. Pemberitahuan saldo masuk/pembatalan dikirim melalui bot.
TERMS,
            'created_by_user_id' => null,
            'created_at' => $now,
        ]);

        // USDT funding starts disabled: an explicit Owner activation plus a
        // registered, qualified rail readiness provider is required.
        DB::table('customer_usdt_funding_policies')->insert([
            'singleton_key' => 'customer_usdt_funding_policy',
            'funding_enabled' => false,
            'confirmation_ttl_seconds' => 600,
            'application_fee_micro' => 0,
            'terms_version' => 1,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS customer_usdt_funding_reservations_guard ON customer_usdt_funding_reservations');
            DB::statement('DROP TRIGGER IF EXISTS customer_usdt_funding_terms_immutable ON customer_usdt_funding_terms');
            DB::statement('DROP FUNCTION IF EXISTS protect_customer_usdt_funding_reservation()');
            DB::statement('DROP FUNCTION IF EXISTS protect_customer_usdt_funding_history()');
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS customer_usdt_funding_reservations_settled_entry_unique');
            DB::statement('DROP INDEX IF EXISTS customer_usdt_funding_reservations_active_customer_unique');
        }

        Schema::dropIfExists('customer_usdt_funding_reservations');
        Schema::dropIfExists('customer_usdt_funding_pauses');
        Schema::dropIfExists('customer_usdt_funding_policies');
        Schema::dropIfExists('customer_usdt_funding_terms');
    }
};
