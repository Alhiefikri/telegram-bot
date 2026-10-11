<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the durable email-work state used only by the credentials-ready
     * event type. Existing guest milestone events keep their pending/sending/
     * sent/failed semantics and never read these columns.
     */
    public function up(): void
    {
        Schema::table('order_notification_events', function (Blueprint $table): void {
            $table->text('recipient_email')->nullable()->after('event_type');
            $table->timestamp('next_attempt_at')->nullable()->after('delivery_started_at');

            $table->index(
                ['event_type', 'status', 'next_attempt_at'],
                'order_notification_credential_work_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_notification_events', function (Blueprint $table): void {
            $table->dropIndex('order_notification_credential_work_index');
            $table->dropColumn(['recipient_email', 'next_attempt_at']);
        });
    }
};
