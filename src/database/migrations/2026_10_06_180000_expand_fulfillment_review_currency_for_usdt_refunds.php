<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_fulfillment_reviews', function (Blueprint $table): void {
            $table->string('currency', 4)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('manual_fulfillment_reviews')->whereRaw('LENGTH(currency) > 3')->exists()) {
            throw new RuntimeException('Cannot shrink manual_fulfillment_reviews.currency while four-character currency values exist.');
        }

        Schema::table('manual_fulfillment_reviews', function (Blueprint $table): void {
            $table->string('currency', 3)->change();
        });
    }
};
