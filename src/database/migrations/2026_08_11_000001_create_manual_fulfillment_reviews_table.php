<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_fulfillment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('reason');
            $table->string('state')->index();
            $table->string('provider_reference')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_fulfillment_reviews');
    }
};
