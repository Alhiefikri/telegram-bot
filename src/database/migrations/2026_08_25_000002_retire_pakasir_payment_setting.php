<?php

use App\Support\PaymentProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This is the expand/data cutover phase. The setting row is locked before
     * it is read so an owner switch and this migration cannot both commit a
     * provider transition from the same version.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $settings = DB::table('manual_payment_settings')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'is_active', 'payment_mode', 'payment_provider_version']);

            foreach ($settings as $setting) {
                if (! (bool) $setting->is_active
                    || strtolower(trim((string) $setting->payment_mode)) !== PaymentProvider::PAKASIR) {
                    continue;
                }

                $now = now();
                $version = (int) ($setting->payment_provider_version ?? 0) + 1;
                $updated = DB::table('manual_payment_settings')
                    ->where('id', $setting->id)
                    ->whereRaw('LOWER(payment_mode) = ?', [PaymentProvider::PAKASIR])
                    ->update([
                        'payment_mode' => PaymentProvider::KLIKQRIS,
                        'payment_provider_version' => $version,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    continue;
                }

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => 'payment_provider.switched',
                    'subject_type' => 'App\\Models\\ManualPaymentSetting',
                    'subject_id' => (string) $setting->id,
                    'old_values' => json_encode(['provider' => PaymentProvider::PAKASIR], JSON_THROW_ON_ERROR),
                    'new_values' => json_encode(['provider' => PaymentProvider::KLIKQRIS], JSON_THROW_ON_ERROR),
                    'ip_address' => null,
                    'created_at' => $now,
                ]);
            }
            $this->routeLegacyQueuePayloads();
        }, attempts: 5);
    }

    private function routeLegacyQueuePayloads(): void
    {
        foreach (['jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where('queue', 'payments')
                ->where('payload', 'like', '%ProcessProviderCancellationAttempt%')
                ->update(['queue' => 'notifications']);

            DB::table($table)
                ->where('queue', '<>', 'telegram-outbound')
                ->where('payload', 'like', '%SendPakasirCheckoutInstructions%')
                ->update(['queue' => 'telegram-outbound']);
        }
    }

    /**
     * Provider data cutovers are not reversed automatically. The provider
     * switch may have changed again after this migration committed, and
     * restoring Pakasir here would mutate an operator decision.
     */
    public function down(): void {}
};
