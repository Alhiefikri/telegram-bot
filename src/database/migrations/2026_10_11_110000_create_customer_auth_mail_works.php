<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->enum('password_scheme', ['raw', 'sha384-v1'])->default('raw');
        });

        Schema::create('customer_auth_mail_works', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auth_token_id')->unique()->constrained('customer_auth_tokens')->restrictOnDelete();
            $table->text('payload')->nullable();
            $table->enum('status', ['pending', 'sent', 'cancelled', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('next_attempt_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_auth_mail_works');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('password_scheme');
        });
    }
};
