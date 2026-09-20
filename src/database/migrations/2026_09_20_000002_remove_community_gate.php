<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('telegram_community_gate_prompts');

        Schema::table('store_channel_settings', function (Blueprint $table): void {
            $table->dropColumn('community_gate_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('store_channel_settings', function (Blueprint $table): void {
            $table->boolean('community_gate_enabled')->default(false)->after('telegram_chat_id');
        });

        Schema::create('telegram_community_gate_prompts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('chat_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamps();
        });
    }
};
