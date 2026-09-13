<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->string('telegram_welcome_banner_path', 512)->nullable();
            $table->string('telegram_welcome_banner_file_id', 512)->nullable();
            $table->unsignedBigInteger('telegram_welcome_banner_version')->default(0);
        });

        Schema::table('telegram_navigation_roots', function (Blueprint $table): void {
            $table->unsignedBigInteger('banner_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_navigation_roots', function (Blueprint $table): void {
            $table->dropColumn('banner_version');
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_welcome_banner_path',
                'telegram_welcome_banner_file_id',
                'telegram_welcome_banner_version',
            ]);
        });
    }
};
