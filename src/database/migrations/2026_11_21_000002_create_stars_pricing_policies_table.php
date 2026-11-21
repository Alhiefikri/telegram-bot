<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stars_pricing_policies', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->bigInteger('idr_per_usd')->nullable();
            $table->bigInteger('reward_micro_usd')->default(13_000);
            $table->smallInteger('reserve_bps')->nullable();
            $table->boolean('issuance_enabled')->default(false);
            $table->bigInteger('version')->default(0);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE stars_pricing_policies
                ADD CONSTRAINT stars_pricing_policy_valid CHECK (
                    id = 1 AND version >= 0 AND reward_micro_usd > 0
                    AND (
                        (idr_per_usd IS NULL AND reserve_bps IS NULL AND NOT issuance_enabled)
                        OR (idr_per_usd IS NOT NULL AND idr_per_usd > 0
                            AND reserve_bps IS NOT NULL AND reserve_bps BETWEEN 0 AND 9999)
                    )
                )
                SQL);
        }

        DB::table('stars_pricing_policies')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stars_pricing_policies');
    }
};
