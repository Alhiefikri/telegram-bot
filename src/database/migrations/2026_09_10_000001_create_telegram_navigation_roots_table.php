<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_navigation_roots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('media_kind', 16);
            $table->string('screen', 32);
            $table->string('locale', 8)->nullable();
            $table->string('banner_reference', 512)->nullable();
            $table->unsignedBigInteger('generation')->default(1);
            $table->string('render_token', 36)->nullable();
            $table->timestamp('render_started_at')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'message_id'], 'telegram_navigation_roots_chat_message_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_navigation_roots');
    }
};
