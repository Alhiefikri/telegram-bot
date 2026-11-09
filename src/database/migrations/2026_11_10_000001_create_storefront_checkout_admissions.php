<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_checkout_subjects', function (Blueprint $table): void {
            $table->char('subject_hash', 64)->primary();
        });
        Schema::create('storefront_checkout_admissions', function (Blueprint $table): void {
            $table->id();
            $table->char('browser_hash', 64)->index();
            $table->char('ip_hash', 64)->nullable()->index();
            $table->char('checkout_key', 64)->unique();
            $table->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_checkout_admissions');
        Schema::dropIfExists('storefront_checkout_subjects');
    }
};
