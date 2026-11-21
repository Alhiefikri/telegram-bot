<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_stars_refunds', function (Blueprint $table): void {
            $table->foreignUuid('payment_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('external_money_receipt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('state', 16);
            $table->timestamp('requested_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmation_source', 16)->nullable();
            $table->foreignId('telegram_update_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->index(['state', 'requested_at']);
        });
        Schema::create('telegram_stars_refund_reviews', function (Blueprint $table): void {
            $table->string('evidence_key', 64)->primary();
            $table->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('telegram_update_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason', 40);
            $table->timestamp('created_at')->index();
            $table->index(['payment_id', 'reason']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE telegram_stars_refunds ADD CONSTRAINT stars_refund_confirmed_evidence CHECK (
                    (state IN ('pending', 'review') AND confirmed_at IS NULL AND confirmation_source IS NULL AND telegram_update_id IS NULL)
                    OR (state = 'confirmed' AND confirmed_at IS NOT NULL AND confirmation_source IS NOT NULL AND (
                        (confirmation_source = 'webhook' AND telegram_update_id IS NOT NULL)
                        OR (confirmation_source IN ('ack', 'history') AND telegram_update_id IS NULL)
                    ))
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('telegram_stars_refunds')->exists() || DB::table('telegram_stars_refund_reviews')->exists()) {
            throw new RuntimeException('Refusing to drop Telegram Stars refund evidence.');
        }

        Schema::dropIfExists('telegram_stars_refund_reviews');
        Schema::dropIfExists('telegram_stars_refunds');
    }
};
