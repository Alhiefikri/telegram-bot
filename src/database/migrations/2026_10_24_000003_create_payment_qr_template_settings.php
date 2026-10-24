<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_qr_template_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('qr_x')->nullable();
            $table->unsignedInteger('qr_y')->nullable();
            $table->unsignedInteger('qr_width')->nullable();
            $table->unsignedInteger('qr_height')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });

        DB::table('payment_qr_template_settings')->insert([
            'singleton_key' => 'payment-qr-template',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_qr_template_settings');
    }
};
