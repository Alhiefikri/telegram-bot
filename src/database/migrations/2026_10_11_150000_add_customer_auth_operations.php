<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_auth_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        DB::table('customer_auth_settings')->insert([
            'singleton_key' => 'customer_auth', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('customer_telegram_auth_claims', function (Blueprint $table): void {
            $table->index(['customer_id', 'failure'], 'customer_telegram_claim_customer_failure');
            $table->index(['proof_customer_id', 'failure'], 'customer_telegram_claim_proof_failure');
        });
        Schema::table('customer_google_auth_claims', function (Blueprint $table): void {
            $table->index(['customer_id', 'consumed_at'], 'customer_google_claim_customer_consumed');
        });
        Schema::table('customer_auth_mail_works', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'customer_auth_mail_status_retention');
            $table->index(['status', 'last_error_code'], 'customer_auth_mail_status_error');
        });
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->index(['queue', 'id'], 'failed_jobs_queue_health');
        });
    }

    public function down(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->dropIndex('failed_jobs_queue_health');
        });
        Schema::table('customer_auth_mail_works', function (Blueprint $table): void {
            $table->dropIndex('customer_auth_mail_status_retention');
            $table->dropIndex('customer_auth_mail_status_error');
        });
        Schema::table('customer_google_auth_claims', function (Blueprint $table): void {
            $table->dropIndex('customer_google_claim_customer_consumed');
        });
        Schema::table('customer_telegram_auth_claims', function (Blueprint $table): void {
            $table->dropIndex('customer_telegram_claim_customer_failure');
            $table->dropIndex('customer_telegram_claim_proof_failure');
        });
        Schema::dropIfExists('customer_auth_settings');
    }
};
