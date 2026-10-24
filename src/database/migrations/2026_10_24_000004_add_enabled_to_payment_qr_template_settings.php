<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_qr_template_settings', function (Blueprint $table): void {
            $table->boolean('enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('payment_qr_template_settings', function (Blueprint $table): void {
            $table->dropColumn('enabled');
        });
    }
};
