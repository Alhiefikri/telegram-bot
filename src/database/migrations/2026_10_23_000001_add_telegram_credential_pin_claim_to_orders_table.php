<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('telegram_credential_pin_claim_token', 64)->nullable()->after('telegram_credential_pinned_at');
            $table->timestamp('telegram_credential_pin_claimed_at')->nullable()->after('telegram_credential_pin_claim_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_credential_pin_claim_token',
                'telegram_credential_pin_claimed_at',
            ]);
        });
    }
};
