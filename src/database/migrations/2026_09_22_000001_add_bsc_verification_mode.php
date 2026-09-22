<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_bsc_details', function (Blueprint $table): void {
            $table->string('verification_mode', 24)
                ->default('transaction_hash')
                ->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_bsc_details', function (Blueprint $table): void {
            $table->dropColumn('verification_mode');
        });
    }
};
