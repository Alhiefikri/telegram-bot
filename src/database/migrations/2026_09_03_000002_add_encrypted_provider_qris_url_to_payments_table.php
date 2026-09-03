<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // Signed provider URLs are bearer credentials and must not live in
            // the generic, audit-safe provider_payload JSON document.
            $table->text('provider_qris_url')->nullable()->after('provider_payload');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('provider_qris_url');
        });
    }
};
