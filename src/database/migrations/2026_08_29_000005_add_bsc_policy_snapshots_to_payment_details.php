<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_MIN_CONFIRMATIONS = 3;

    private const DEFAULT_CONFIRMATION_GRACE_MINUTES = 3;

    public function up(): void
    {
        Schema::table('payment_bsc_details', function (Blueprint $table): void {
            $table->unsignedTinyInteger('required_confirmations')->default(self::DEFAULT_MIN_CONFIRMATIONS);
            $table->boolean('requires_finalized_block')->default(false);
            $table->unsignedTinyInteger('confirmation_grace_minutes')->default(self::DEFAULT_CONFIRMATION_GRACE_MINUTES);
        });

        $setting = DB::table('manual_payment_settings')
            ->where('is_active', true)
            ->oldest('id')
            ->first([
                'bsc_min_confirmations',
                'bsc_require_finalized_block',
                'bsc_confirmation_grace_minutes',
            ]);

        if ($setting === null) {
            return;
        }

        DB::table('payment_bsc_details')->update([
            'required_confirmations' => max(
                self::DEFAULT_MIN_CONFIRMATIONS,
                (int) ($setting->bsc_min_confirmations ?: self::DEFAULT_MIN_CONFIRMATIONS),
            ),
            'requires_finalized_block' => (bool) $setting->bsc_require_finalized_block,
            'confirmation_grace_minutes' => max(
                0,
                (int) ($setting->bsc_confirmation_grace_minutes ?? self::DEFAULT_CONFIRMATION_GRACE_MINUTES),
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('payment_bsc_details', function (Blueprint $table): void {
            $table->dropColumn([
                'required_confirmations',
                'requires_finalized_block',
                'confirmation_grace_minutes',
            ]);
        });
    }
};
