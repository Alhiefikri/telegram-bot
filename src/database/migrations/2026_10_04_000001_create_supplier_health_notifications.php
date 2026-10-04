<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->timestampTz('balance_observed_at')->nullable();
        });
        foreach (['supplier_fulfillments', 'supplier_purchases'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('health_review_episode')->nullable();
            });
            DB::table($tableName)->whereIn('state', ['review', 'ambiguous'])
                ->update(['health_review_episode' => (string) Str::uuid()]);
        }
        Schema::create('supplier_health_scans', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('supplier_cursor')->default(0);
            $table->unsignedBigInteger('fulfillment_cursor')->default(0);
        });
        DB::table('supplier_health_scans')->insert(['id' => 1]);
        Schema::create('supplier_health_states', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('low_active')->default(false);
            $table->json('error_codes');
        });
        Schema::create('supplier_health_reviews', function (Blueprint $table): void {
            $table->foreignId('supplier_fulfillment_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('active_identities');
        });
        Schema::create('supplier_health_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->timestampTz('created_at');
        });
        // Old sync timestamps did not materialize any outbound effect. This
        // one-time cutover precedes the new writer and never touches sent history.
        DB::table('suppliers')->whereNotNull('low_balance_alerted_at')->update(['low_balance_alerted_at' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_health_events');
        Schema::dropIfExists('supplier_health_reviews');
        Schema::dropIfExists('supplier_health_states');
        Schema::dropIfExists('supplier_health_scans');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('balance_observed_at'));
        foreach (['supplier_fulfillments', 'supplier_purchases'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('health_review_episode'));
        }
    }
};
