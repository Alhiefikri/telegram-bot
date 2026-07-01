<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_channel_settings', function (Blueprint $table) {
            $table->id();
            $table->string('singleton_key')->default('store_channel')->unique();
            $table->string('channel_username')->nullable();
            $table->string('channel_link')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->boolean('community_gate_enabled')->default(false);
            $table->boolean('sales_proof_enabled')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_success')->nullable();
            $table->string('last_test_message_id')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_channel_settings');
    }
};
