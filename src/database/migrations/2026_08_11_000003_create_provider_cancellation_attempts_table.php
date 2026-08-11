<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_cancellation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('state')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('provider_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_cancellation_attempts');
    }
};
