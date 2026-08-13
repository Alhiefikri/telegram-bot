<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_broadcasts', function (Blueprint $table): void {
            $table->json('reply_markup')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_broadcasts', function (Blueprint $table): void {
            $table->dropColumn('reply_markup');
        });
    }
};
