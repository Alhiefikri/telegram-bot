<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->index()->constrained()->restrictOnDelete();
            $table->string('provider')->index();
            $table->string('merchant_ref')->unique();
            $table->string('provider_reference')->nullable()->unique();
            $table->string('method');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('IDR');
            $table->string('status')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
