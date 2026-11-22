<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE carts DROP CONSTRAINT carts_origin_check');
            DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_origin_check CHECK (origin IN ('customer', 'api', 'telegram_direct', 'web_balance'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (DB::table('carts')->where('origin', 'web_balance')->exists()) {
                throw new RuntimeException('Refusing to invalidate persisted Storefront Balance carts.');
            }

            DB::statement('ALTER TABLE carts DROP CONSTRAINT carts_origin_check');
            DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_origin_check CHECK (origin IN ('customer', 'api', 'telegram_direct'))");
        }
    }
};
