<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable();
            $table->unsignedInteger('received_count')->default(1);
            $table->timestamp('last_received_at')->nullable();
            $table->unique(['provider', 'event_type', 'idempotency_key'], 'payment_events_provider_event_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropUnique('payment_events_provider_event_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'received_count', 'last_received_at']);
        });
    }
};
