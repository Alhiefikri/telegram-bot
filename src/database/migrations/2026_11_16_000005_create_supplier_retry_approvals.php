<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_retry_approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supplier_fulfillment_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_purchase_id')->nullable()->constrained('supplier_purchases')->restrictOnDelete();
            $table->foreignId('approved_purchase_id')->nullable()->unique()->constrained('supplier_purchases')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('product_supplier_sources')->restrictOnDelete();
            $table->unsignedInteger('source_version');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_catalog_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('supplier_version');
            $table->unsignedInteger('quantity');
            $table->string('currency', 10);
            $table->decimal('exchange_rate_idr', 16, 6);
            $table->unsignedBigInteger('cost_micros');
            $table->unsignedBigInteger('cost_idr');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_retry_approvals');
    }
};
