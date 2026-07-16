<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_create_state')->nullable()->index()->after('provider_payment_number_hash');
            $table->timestamp('provider_create_attempted_at')->nullable()->after('provider_create_state');
            $table->string('provider_create_last_error', 500)->nullable()->after('provider_create_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['provider_create_state']);
            $table->dropColumn(['provider_create_state', 'provider_create_attempted_at', 'provider_create_last_error']);
        });
    }
};
