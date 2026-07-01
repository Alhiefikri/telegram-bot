<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table) {
            $table->dropColumn([
                'dana_account_name',
                'dana_account_number',
                'dana_qr_image_path',
                'dana_qr_image_url',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table) {
            $table->string('dana_account_name')->nullable()->after('payment_mode');
            $table->string('dana_account_number')->nullable()->after('dana_account_name');
            $table->string('dana_qr_image_path')->nullable()->after('dana_account_number');
            $table->string('dana_qr_image_url')->nullable()->after('dana_qr_image_path');
        });
    }
};
