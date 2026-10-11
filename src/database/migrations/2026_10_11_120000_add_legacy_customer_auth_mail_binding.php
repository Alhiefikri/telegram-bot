<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_auth_mail_works', function (Blueprint $table): void {
            $table->unsignedBigInteger('auth_token_id')->nullable()->change();
            $table->foreignId('login_link_id')->nullable()->unique()->constrained('customer_login_links')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_auth_mail_works ADD CONSTRAINT customer_auth_mail_binding_check CHECK ((auth_token_id IS NULL) <> (login_link_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_auth_mail_works DROP CONSTRAINT customer_auth_mail_binding_check');
        }
        DB::table('customer_auth_mail_works')->whereNotNull('login_link_id')->delete();
        Schema::table('customer_auth_mail_works', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('login_link_id');
            $table->unsignedBigInteger('auth_token_id')->nullable(false)->change();
        });
    }
};
