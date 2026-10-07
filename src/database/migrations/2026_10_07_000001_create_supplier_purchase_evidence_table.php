<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_purchase_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('evidence_source', 32);
            $table->string('provider_reference', 255);
            $table->text('note');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['supplier_purchase_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_purchase_evidence');
    }
};
