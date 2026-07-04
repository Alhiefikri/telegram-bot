<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->jsonb('provider_payload')->nullable()->after('provider_reference');
            $table->bigInteger('provider_fee')->nullable()->after('provider_payload');
            $table->bigInteger('provider_total_payment')->nullable()->after('provider_fee');
            $table->string('provider_payment_method')->nullable()->after('provider_total_payment');
            $table->timestamp('provider_created_at')->nullable()->after('provider_payment_method');
            $table->timestamp('provider_expires_at')->nullable()->after('provider_created_at');
            $table->text('provider_payment_number')->nullable()->after('provider_expires_at');
            $table->char('provider_payment_number_hash', 64)->nullable()->after('provider_payment_number')->index('payments_provider_payment_number_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_provider_payment_number_hash_index');
            $table->dropColumn([
                'provider_payload',
                'provider_fee',
                'provider_total_payment',
                'provider_payment_method',
                'provider_created_at',
                'provider_expires_at',
                'provider_payment_number',
                'provider_payment_number_hash',
            ]);
        });
    }
};
