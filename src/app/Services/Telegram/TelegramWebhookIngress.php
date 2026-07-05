<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Support\DogfoodTiming;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramWebhookIngress
{
    public function __construct(private readonly TelegramUpdateShapeValidator $updateShapes) {}

    public function receive(Request $request): TelegramWebhookIngressResult
    {
        $startedAt = DogfoodTiming::now();
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '' && ! app()->environment(['local', 'testing'])) {
            return new TelegramWebhookIngressResult(['message' => 'Telegram webhook secret is not configured.'], 500);
        }

        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return new TelegramWebhookIngressResult(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['update_id' => ['required', 'integer', 'min:1']]);
        $payload = $request->all();

        if (! $this->updateShapes->supports($payload)) {
            return new TelegramWebhookIngressResult(['message' => 'Unsupported update type'], 422);
        }

        $inserted = DB::table('telegram_updates')->insertOrIgnore([
            'update_id' => (int) $validated['update_id'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $update = TelegramUpdate::query()->where('update_id', (int) $validated['update_id'])->firstOrFail();
        $duplicate = $inserted !== 1;
        $jobEnqueuedAt = null;

        if ($update->processed_at === null) {
            $jobEnqueuedAt = now();
            $this->dispatchProcessor($update->id, $jobEnqueuedAt->toISOString());
        }

        DogfoodTiming::log('dogfood.telegram.webhook_ack', [
            'update_id' => (int) $validated['update_id'],
            'telegram_update_id' => $update->id,
            'correlation_id' => DogfoodTiming::correlation($validated['update_id']),
            'ack_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            'job_enqueued_at' => $jobEnqueuedAt?->toISOString(),
            'duplicate' => $duplicate,
        ]);

        return new TelegramWebhookIngressResult(['ok' => true]);
    }

    private function dispatchProcessor(int $telegramUpdateId, string $jobEnqueuedAt): void
    {
        if (app()->environment('testing')) {
            ProcessTelegramUpdate::dispatch($telegramUpdateId, $jobEnqueuedAt);

            return;
        }

        app()->terminating(static fn () => ProcessTelegramUpdate::dispatch($telegramUpdateId, $jobEnqueuedAt));
    }
}
