<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_purchases', fn (Blueprint $table) => $table->string('credential_fingerprint', 64)->nullable());
        Schema::table('supplier_deposits', fn (Blueprint $table) => $table->string('credential_fingerprint', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('supplier_purchases', fn (Blueprint $table) => $table->dropColumn('credential_fingerprint'));
        Schema::table('supplier_deposits', fn (Blueprint $table) => $table->dropColumn('credential_fingerprint'));
    }
};
