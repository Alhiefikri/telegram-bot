<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_payment_proof_prompts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('target_type', 16);
            $table->string('target_id');
            $table->timestamp('created_at');
            $table->unique(['chat_id', 'message_id'], 'telegram_payment_proof_prompt_message_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_payment_proof_prompts');
    }
};
