<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table) {
            $table->string('payment_mode')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });
    }
};
