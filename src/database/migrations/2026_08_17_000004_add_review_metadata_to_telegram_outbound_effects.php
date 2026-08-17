<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_outbound_effects', function (Blueprint $table): void {
            $table->uuid('supersedes_effect_id')->nullable();
            $table->index('supersedes_effect_id', 'telegram_outbound_effects_supersedes_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_outbound_effects', function (Blueprint $table): void {
            $table->dropIndex('telegram_outbound_effects_supersedes_idx');
            $table->dropColumn('supersedes_effect_id');
        });
    }
};
