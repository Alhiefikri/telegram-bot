<?php

use App\Services\UsdtReceivers\CheckoutReceiverAddressClaimBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_receiver_address_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chain_id');
            $table->char('address_normalized', 42);
            $table->string('channel', 32);
            $table->timestamp('claimed_at');
            $table->unique(['chain_id', 'address_normalized'], 'usdt_receiver_claims_address_unique');
        });

        Schema::create('customer_usdt_deposit_receivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('address_claim_id')
                ->unique('usdt_deposit_receivers_claim_unique')
                ->constrained('usdt_receiver_address_claims', 'id', 'usdt_deposit_receivers_claim_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('chain_id');
            $table->char('address', 42);
            $table->char('address_normalized', 42);
            $table->char('token_contract', 42);
            $table->unsignedSmallInteger('token_decimals');
            $table->string('custody_namespace', 64)->default('operator');
            $table->string('source', 32);
            $table->string('verification_status', 32);
            $table->string('verification_method', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->string('retirement_reason', 191)->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['chain_id', 'address_normalized'], 'usdt_deposit_receivers_address_unique');
            $table->index(['retired_at', 'id'], 'usdt_deposit_receivers_monitor_index');
            $table->index(['customer_id', 'retired_at'], 'usdt_deposit_receivers_customer_index');
        });

        Schema::create('usdt_deposit_receiver_ownership_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('receiver_id')
                ->constrained('customer_usdt_deposit_receivers', 'id', 'usdt_deposit_receiver_challenge_receiver_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('wallet_address', 42);
            $table->unsignedBigInteger('chain_id');
            $table->string('purpose', 64);
            $table->text('message');
            $table->string('message_hash', 64);
            $table->string('nonce_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['receiver_id', 'used_at'], 'usdt_deposit_receiver_challenge_usable_index');
        });

        Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
            $table->unsignedBigInteger('address_claim_id')->nullable();
            $table->foreign('address_claim_id', 'bsc_receivers_address_claim_fk')
                ->references('id')
                ->on('usdt_receiver_address_claims')
                ->restrictOnDelete();
        });

        // The unique index is created separately from the ALTER above: SQLite
        // rebuilds the table to add the foreign key, which would drop an index
        // declared in the same blueprint.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX bsc_receivers_address_claim_unique ON bsc_payment_receivers (address_claim_id)');
        } else {
            Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
                $table->unique('address_claim_id', 'bsc_receivers_address_claim_unique');
            });
        }

        $this->backfillCheckoutClaims();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bsc_payment_receivers ALTER COLUMN address_claim_id SET NOT NULL');
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            // One non-retired deposit receiver per Customer: rotation retires the
            // previous address before a new one is provisioned, and retired rows
            // stay monitored for late money.
            DB::statement(
                'CREATE UNIQUE INDEX usdt_deposit_receivers_active_customer_unique'
                .' ON customer_usdt_deposit_receivers (customer_id) WHERE retired_at IS NULL',
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS customer_usdt_deposit_receivers_immutable ON customer_usdt_deposit_receivers');
            DB::statement('DROP TRIGGER IF EXISTS customer_usdt_deposit_receivers_guard ON customer_usdt_deposit_receivers');
            DB::statement('DROP TRIGGER IF EXISTS bsc_payment_receivers_address_claim_guard ON bsc_payment_receivers');
            DB::statement('DROP TRIGGER IF EXISTS usdt_receiver_address_claims_immutable ON usdt_receiver_address_claims');
            DB::statement('DROP FUNCTION IF EXISTS protect_customer_usdt_deposit_receivers()');
            DB::statement('DROP FUNCTION IF EXISTS enforce_usdt_deposit_receiver_claim()');
            DB::statement('DROP FUNCTION IF EXISTS enforce_bsc_receiver_address_claim()');
            DB::statement('DROP FUNCTION IF EXISTS protect_usdt_receiver_address_claims()');
        }

        DB::statement('DROP INDEX IF EXISTS usdt_deposit_receivers_active_customer_unique');
        DB::statement('DROP INDEX IF EXISTS bsc_receivers_address_claim_unique');

        // The foreign key must go first: SQLite refuses to drop a column that a
        // remaining foreign key definition still references. PostgreSQL drops it
        // by name; SQLite names foreign keys by column inside its table rebuild.
        $driver = DB::getDriverName();
        Schema::table('bsc_payment_receivers', function (Blueprint $table) use ($driver): void {
            if ($driver === 'pgsql') {
                $table->dropForeign('bsc_receivers_address_claim_fk');
            } else {
                $table->dropForeign(['address_claim_id']);
            }
        });

        Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
            $table->dropColumn('address_claim_id');
        });

        Schema::dropIfExists('usdt_deposit_receiver_ownership_challenges');
        Schema::dropIfExists('customer_usdt_deposit_receivers');
        Schema::dropIfExists('usdt_receiver_address_claims');
    }

    /**
     * Existing checkout receivers become permanent checkout claims. Addresses are
     * globally unique on the checkout table already, so no duplicate handling is
     * needed; historical overlap with a deposit binding cannot exist yet.
     */
    private function backfillCheckoutClaims(): void
    {
        (new CheckoutReceiverAddressClaimBackfill)->run();
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_usdt_receiver_address_claims() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'USDT receiver address claims are permanent and channel-exclusive';
END $$;
SQL);
        DB::statement('CREATE TRIGGER usdt_receiver_address_claims_immutable BEFORE UPDATE OR DELETE ON usdt_receiver_address_claims FOR EACH ROW EXECUTE FUNCTION protect_usdt_receiver_address_claims()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_bsc_receiver_address_claim() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    claim_channel text;
    claim_chain bigint;
    claim_address text;
BEGIN
    SELECT channel, chain_id, address_normalized
      INTO claim_channel, claim_chain, claim_address
      FROM usdt_receiver_address_claims
     WHERE id = NEW.address_claim_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'BSC receiving addresses require an existing USDT receiver address claim';
    END IF;

    IF claim_channel <> 'checkout' THEN
        RAISE EXCEPTION 'BSC receiving addresses cannot share a % receiver address claim', claim_channel;
    END IF;

    IF claim_chain <> NEW.chain_id OR claim_address <> NEW.address_normalized THEN
        RAISE EXCEPTION 'BSC receiving address claim identity mismatch';
    END IF;

    RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE TRIGGER bsc_payment_receivers_address_claim_guard BEFORE INSERT OR UPDATE ON bsc_payment_receivers FOR EACH ROW EXECUTE FUNCTION enforce_bsc_receiver_address_claim()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_usdt_deposit_receiver_claim() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    claim_channel text;
    claim_chain bigint;
    claim_address text;
BEGIN
    SELECT channel, chain_id, address_normalized
      INTO claim_channel, claim_chain, claim_address
      FROM usdt_receiver_address_claims
     WHERE id = NEW.address_claim_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'USDT deposit receivers require an existing USDT receiver address claim';
    END IF;

    IF claim_channel <> 'customer_deposit' THEN
        RAISE EXCEPTION 'USDT deposit receivers cannot share a % receiver address claim', claim_channel;
    END IF;

    IF claim_chain <> NEW.chain_id OR claim_address <> NEW.address_normalized THEN
        RAISE EXCEPTION 'USDT deposit receiver address claim identity mismatch';
    END IF;

    RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE TRIGGER customer_usdt_deposit_receivers_guard BEFORE INSERT OR UPDATE ON customer_usdt_deposit_receivers FOR EACH ROW EXECUTE FUNCTION enforce_usdt_deposit_receiver_claim()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_customer_usdt_deposit_receivers() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.verification_status <> 'pending_verification' OR OLD.retired_at IS NOT NULL THEN
            RAISE EXCEPTION 'Bound USDT deposit receivers are immutable Customer history';
        END IF;

        RETURN OLD;
    END IF;

    IF NEW.customer_id <> OLD.customer_id
        OR NEW.address_claim_id <> OLD.address_claim_id
        OR NEW.chain_id <> OLD.chain_id
        OR NEW.address <> OLD.address
        OR NEW.address_normalized <> OLD.address_normalized
        OR NEW.token_contract <> OLD.token_contract
        OR NEW.token_decimals <> OLD.token_decimals
        OR NEW.custody_namespace <> OLD.custody_namespace
        OR NEW.source <> OLD.source
        OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'USDT deposit receiver identity is immutable';
    END IF;

    IF OLD.verification_status <> 'pending_verification'
        AND NEW.verification_status <> OLD.verification_status THEN
        RAISE EXCEPTION 'USDT deposit receiver verification is final';
    END IF;

    IF OLD.retired_at IS NOT NULL AND NEW.retired_at IS DISTINCT FROM OLD.retired_at THEN
        RAISE EXCEPTION 'USDT deposit receivers cannot be un-retired';
    END IF;

    RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE TRIGGER customer_usdt_deposit_receivers_immutable BEFORE UPDATE OR DELETE ON customer_usdt_deposit_receivers FOR EACH ROW EXECUTE FUNCTION protect_customer_usdt_deposit_receivers()');
    }
};
