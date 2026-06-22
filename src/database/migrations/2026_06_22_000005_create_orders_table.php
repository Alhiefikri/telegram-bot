<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->index()->constrained()->restrictOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('order_status')->index();
            $table->string('payment_status')->index();
            $table->bigInteger('total_amount');
            $table->string('currency', 3)->default('IDR');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
