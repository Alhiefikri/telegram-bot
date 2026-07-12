<?php

use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    public function up(): void
    {
        $failedJobIds = [];
        $telegramUpdateIds = [];

        DB::table('failed_jobs')->orderBy('id')->eachById(function (object $failedJob) use (&$failedJobIds, &$telegramUpdateIds): void {
            $payload = json_decode((string) $failedJob->payload, true);
            $command = data_get($payload, 'data.command');
            if (data_get($payload, 'displayName') !== ProcessTelegramUpdate::class || ! is_string($command) || preg_match('/"telegramUpdateId";i:(\d+);/', $command, $matches) !== 1) {
                return;
            }

            $failedJobIds[] = $failedJob->id;
            $telegramUpdateIds[] = (int) $matches[1];
        });

        DB::transaction(function () use ($failedJobIds, $telegramUpdateIds): void {
            if ($telegramUpdateIds !== []) {
                DB::table('telegram_updates')
                    ->whereIn('id', array_unique($telegramUpdateIds))
                    ->whereNull('processed_at')
                    ->whereNull('processing_started_at')
                    ->update(['processing_started_at' => now()]);
            }

            if ($failedJobIds !== []) {
                DB::table('failed_jobs')->whereIn('id', $failedJobIds)->delete();
            }
        });
    }

    public function down(): void {}
};
