<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('dana_account_name')->nullable();
            $table->string('dana_account_number')->nullable();
            $table->string('dana_qr_image_path')->nullable();
            $table->string('dana_qr_image_url')->nullable();
            $table->string('admin_contact_url')->nullable();
            $table->string('admin_username')->nullable();
            $table->string('admin_telegram_chat_id')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_settings');
    }
};
