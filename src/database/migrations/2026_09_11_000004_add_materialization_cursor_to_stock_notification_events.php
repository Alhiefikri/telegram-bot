<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_notification_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('materialization_cursor')->nullable()->after('recipients_snapshotted_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_notification_events', function (Blueprint $table): void {
            $table->dropColumn('materialization_cursor');
        });
    }
};
