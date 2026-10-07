<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_order_history_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('outcome', 32);
            $table->string('error_code', 80)->nullable();
            $table->unsignedSmallInteger('requested_limit');
            $table->unsignedInteger('reported_count')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('dispatch_at');
            $table->timestamp('requested_at');
            $table->timestamp('observed_at');
            $table->timestamp('coverage_start')->nullable();
            $table->timestamp('coverage_end')->nullable();
            $table->string('history_timezone', 64);
            $table->char('response_sha256', 64)->nullable();
            $table->text('candidate_order_ids')->nullable();
            $table->timestamps();

            $table->index(['supplier_purchase_id', 'observed_at']);
            $table->index(['outcome', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_history_reconciliations');
    }
};
