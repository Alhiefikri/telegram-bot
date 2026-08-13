<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_broadcasts', function (Blueprint $table): void {
            $table->timestamp('queue_dispatched_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_broadcasts', function (Blueprint $table): void {
            $table->dropColumn('queue_dispatched_at');
        });
    }
};
