<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('description_ms')->nullable()->after('description');
            $table->text('description_en')->nullable()->after('description_ms');
            $table->text('warranty_policy_ms')->nullable()->after('warranty_policy');
            $table->text('warranty_policy_en')->nullable()->after('warranty_policy_ms');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['description_ms', 'description_en', 'warranty_policy_ms', 'warranty_policy_en']);
        });
    }
};
