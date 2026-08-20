<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_intake_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // The operation row is claimed before the movement is created. The
            // same transaction fills this column before commit.
            $table->foreignId('inventory_movement_id')->nullable()->unique()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('payload_hash', 64);
            $table->string('mode', 32);
            $table->unsignedInteger('item_count');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->integer('available_before');
            $table->integer('available_after');
            $table->string('ip_address')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at'], 'product_stock_intake_product_created_index');
            $table->index('payload_hash', 'product_stock_intake_payload_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_intake_operations');
    }
};
