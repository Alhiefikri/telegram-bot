<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_kind', 32);
            $table->char('fingerprint', 64);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['identity_kind', 'fingerprint'],
                'credential_identities_kind_fingerprint_unique',
            );
            $table->index('product_stock_item_id', 'credential_identities_stock_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_identities');
    }
};
