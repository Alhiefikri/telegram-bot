<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bsc_receiver_ownership_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('receiver_id')->constrained('bsc_payment_receivers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('wallet_address', 42);
            $table->unsignedBigInteger('chain_id');
            $table->string('purpose', 80);
            $table->text('message');
            $table->char('message_hash', 64);
            $table->char('nonce_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['receiver_id', 'used_at', 'expires_at'], 'bsc_challenges_pending_lookup');
            $table->index(['wallet_address', 'chain_id'], 'bsc_challenges_wallet_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bsc_receiver_ownership_challenges');
    }
};
