<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $settings = DB::table('manual_payment_settings')
            ->where('payment_mode', 'pakasir')
            ->get(['id', 'payment_provider_version']);

        foreach ($settings as $setting) {
            DB::table('manual_payment_settings')
                ->where('id', $setting->id)
                ->update([
                    'payment_mode' => 'klikqris',
                    'payment_provider_version' => (int) $setting->payment_provider_version + 1,
                ]);

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'payment_provider.switched',
                'subject_type' => 'App\\Models\\ManualPaymentSetting',
                'subject_id' => (string) $setting->id,
                'old_values' => json_encode(['provider' => 'pakasir'], JSON_THROW_ON_ERROR),
                'new_values' => json_encode(['provider' => 'klikqris'], JSON_THROW_ON_ERROR),
                'ip_address' => null,
                'created_at' => now(),
            ]);
        }

        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->dropColumn('pakasir_checkout_flow');
        });
    }

    public function down(): void
    {
        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->string('pakasir_checkout_flow')->default('hosted')->after('payment_mode');
        });
    }
};
