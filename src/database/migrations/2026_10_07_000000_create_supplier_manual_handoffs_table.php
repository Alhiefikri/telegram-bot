<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_manual_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_fulfillment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('quantity');
            $table->text('note');
            $table->timestamp('handed_off_at');
            $table->timestamps();

            $table->unique(['supplier_fulfillment_id', 'idempotency_key']);
            $table->index(['supplier_fulfillment_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_manual_handoffs ADD CONSTRAINT supplier_manual_handoff_quantity_guard CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_manual_handoffs DROP CONSTRAINT supplier_manual_handoff_quantity_guard');
        }

        Schema::dropIfExists('supplier_manual_handoffs');
    }
};
