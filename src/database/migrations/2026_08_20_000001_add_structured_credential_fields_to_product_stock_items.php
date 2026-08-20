<?php

use App\Enums\CredentialFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->string('credential_format', 32)
                ->default(CredentialFormat::Legacy->value)
                ->after('order_item_id');
            $table->text('credential_payload')->nullable()->after('credential_format');
            $table->index('credential_format', 'product_stock_items_credential_format_index');
        });

        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->text('secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->text('secret')->nullable(false)->change();
            $table->dropIndex('product_stock_items_credential_format_index');
            $table->dropColumn(['credential_payload', 'credential_format']);
        });
    }
};
