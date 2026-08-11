<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_maintenance_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->boolean('is_active')->default(false);
            $table->text('public_note')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        DB::table('checkout_maintenance_settings')->insert([
            'singleton_key' => 'checkout_maintenance',
            'is_active' => false,
            'public_note' => null,
            'version' => 0,
            'activated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_maintenance_settings');
    }
};
