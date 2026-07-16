<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('currency', 4)->default('IDR')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereRaw('LENGTH(currency) > 3')->exists()) {
            throw new RuntimeException('Cannot shrink payments.currency while four-character currency values exist.');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('currency', 3)->default('IDR')->change();
        });
    }
};
