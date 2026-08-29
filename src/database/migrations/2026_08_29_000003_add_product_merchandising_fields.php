<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_type', 100)->nullable()->after('description');
            $table->string('access_duration', 100)->nullable()->after('product_type');
            $table->string('region', 120)->nullable()->after('access_duration');
            $table->string('account_type', 100)->nullable()->after('region');
            $table->string('device_limit', 100)->nullable()->after('account_type');
            $table->text('included_items')->nullable()->after('device_limit');
            $table->text('limitations')->nullable()->after('included_items');
            $table->text('fulfillment_expectation')->nullable()->after('limitations');
            $table->text('warranty_policy')->nullable()->after('fulfillment_expectation');
            $table->text('support_policy')->nullable()->after('warranty_policy');
            $table->unsignedSmallInteger('featured_rank')->nullable()->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['featured_rank']);
            $table->dropColumn([
                'product_type',
                'access_duration',
                'region',
                'account_type',
                'device_limit',
                'included_items',
                'limitations',
                'fulfillment_expectation',
                'warranty_policy',
                'support_policy',
                'featured_rank',
            ]);
        });
    }
};
