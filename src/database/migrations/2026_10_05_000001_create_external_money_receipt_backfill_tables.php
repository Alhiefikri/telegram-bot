<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_money_receipt_backfill_states', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->string('cursor', 191)->nullable();
            $table->unsignedBigInteger('scanned_count')->default(0);
            $table->unsignedBigInteger('claimed_count')->default(0);
            $table->unsignedBigInteger('replayed_count')->default(0);
            $table->unsignedBigInteger('reserved_count')->default(0);
            $table->unsignedBigInteger('conflict_count')->default(0);
            $table->unsignedBigInteger('review_count')->default(0);
            $table->unsignedBigInteger('skipped_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
        });

        Schema::create('external_money_receipt_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('legacy_type', 32);
            $table->string('legacy_id', 64);
            $table->string('provider', 32)->nullable();
            $table->string('reason', 64);
            $table->json('details')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['legacy_type', 'legacy_id', 'reason'],
                'external_money_receipt_reservations_legacy_unique',
            );
            $table->index(['source', 'resolved_at'], 'external_money_receipt_reservations_source_index');
            $table->index('resolved_at', 'external_money_receipt_reservations_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_money_receipt_reservations');
        Schema::dropIfExists('external_money_receipt_backfill_states');
    }
};
