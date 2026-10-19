<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presentation-only USD top-up presets for the Customer USDT funding policy
 * (#548 follow-up). The JSON list holds positive integer micro-USDT amounts
 * and only seeds the top-up amount buttons: it never caps, gates or rewrites a
 * funding target, and every existing row keeps the seeded default list until
 * an Owner edits it.
 */
return new class extends Migration
{
    /** @var list<int> */
    private const DEFAULT_PRESET_AMOUNTS_MICRO = [1_000_000, 5_000_000, 10_000_000, 25_000_000, 50_000_000, 100_000_000];

    public function up(): void
    {
        Schema::table('customer_usdt_funding_policies', function (Blueprint $table): void {
            $table->json('preset_amounts_micro')->nullable()->after('application_fee_micro');
        });

        DB::table('customer_usdt_funding_policies')
            ->whereNull('preset_amounts_micro')
            ->update([
                'preset_amounts_micro' => json_encode(self::DEFAULT_PRESET_AMOUNTS_MICRO, JSON_THROW_ON_ERROR),
            ]);

        Schema::table('customer_usdt_funding_policies', function (Blueprint $table): void {
            $table->json('preset_amounts_micro')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_usdt_funding_policies', function (Blueprint $table): void {
            $table->dropColumn('preset_amounts_micro');
        });
    }
};
