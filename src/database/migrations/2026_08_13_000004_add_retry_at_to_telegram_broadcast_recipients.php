<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_broadcast_recipients', function (Blueprint $table): void {
            $table->timestamp('retry_at')->nullable()->after('failed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_broadcast_recipients', function (Blueprint $table): void {
            $table->dropColumn('retry_at');
        });
    }
};
