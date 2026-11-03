<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usdt_reorg_incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('receiver_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('usdt_reorg_incidents')->whereNull('receiver_id')->exists()) {
            throw new RuntimeException('Cannot roll back while shared BSC reorg incidents exist.');
        }
        Schema::table('usdt_reorg_incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('receiver_id')->nullable(false)->change();
        });
    }
};
