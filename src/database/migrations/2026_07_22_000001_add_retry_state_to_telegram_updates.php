<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('terminal_failed_at')->nullable()->index();
            $table->string('last_error_class')->nullable();
            $table->timestamp('last_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex(['next_retry_at']);
            $table->dropIndex(['terminal_failed_at']);
            $table->dropColumn([
                'processing_attempts',
                'next_retry_at',
                'terminal_failed_at',
                'last_error_class',
                'last_failed_at',
            ]);
        });
    }
};
