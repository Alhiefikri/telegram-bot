<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('support_contact_channel', 32)->nullable()->after('checkout_key');
            $table->string('support_contact_value', 120)->nullable()->after('support_contact_channel');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['support_contact_channel', 'support_contact_value']);
        });
    }
};
