<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_telegram_stars_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->bigInteger('telegram_user_id');
            $table->bigInteger('chat_id');
            $table->string('merchant_ref')->unique();
            $table->string('invoice_payload', 64)->unique();
            $table->bigInteger('deadline_epoch');
            $table->bigInteger('policy_version');
            $table->bigInteger('idr_per_usd');
            $table->bigInteger('reward_micro_usd');
            $table->smallInteger('reserve_bps');
            $table->bigInteger('subtotal_idr');
            $table->integer('total_xtr');
            $table->json('retail_lines');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE carts DROP CONSTRAINT carts_origin_check;
                ALTER TABLE carts ADD CONSTRAINT carts_origin_check CHECK (origin IN ('customer', 'api', 'telegram_direct'));
                ALTER TABLE payment_telegram_stars_details ADD CONSTRAINT payment_stars_snapshot_valid CHECK (
                    telegram_user_id > 0 AND chat_id = telegram_user_id AND deadline_epoch > 0
                    AND policy_version >= 0 AND idr_per_usd > 0 AND reward_micro_usd > 0
                    AND reserve_bps BETWEEN 0 AND 9999 AND subtotal_idr > 0 AND total_xtr BETWEEN 1 AND 10000
                    AND invoice_payload ~ '^[a-f0-9]{64}$'
                );
                CREATE OR REPLACE FUNCTION reject_payment_stars_snapshot_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'payment_stars_snapshot_immutable';
                END;
                $$;
                CREATE TRIGGER payment_stars_snapshot_immutable BEFORE UPDATE OR DELETE ON payment_telegram_stars_details
                    FOR EACH ROW EXECUTE FUNCTION reject_payment_stars_snapshot_rewrite();
                CREATE TRIGGER payment_stars_identity_immutable BEFORE UPDATE ON payments
                    FOR EACH ROW WHEN (OLD.provider = 'telegram_stars' AND
                        (NEW.id, NEW.order_id, NEW.provider, NEW.method, NEW.currency, NEW.amount, NEW.merchant_ref, NEW.expired_at)
                        IS DISTINCT FROM
                        (OLD.id, OLD.order_id, OLD.provider, OLD.method, OLD.currency, OLD.amount, OLD.merchant_ref, OLD.expired_at))
                    EXECUTE FUNCTION reject_payment_stars_snapshot_rewrite();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('payment_telegram_stars_details')->exists()) {
            throw new RuntimeException('Refusing to drop persisted Telegram Stars checkout snapshots.');
        }

        Schema::dropIfExists('payment_telegram_stars_details');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS payment_stars_identity_immutable ON payments');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_payment_stars_snapshot_rewrite()');
            DB::statement('ALTER TABLE carts DROP CONSTRAINT carts_origin_check');
            DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_origin_check CHECK (origin IN ('customer', 'api'))");
        }
    }
};
