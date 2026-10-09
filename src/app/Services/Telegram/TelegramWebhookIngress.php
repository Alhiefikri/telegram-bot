<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Support\DogfoodTiming;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        // Pinning a delivered receipt creates a service message, not buyer input.
        // Acknowledge it before minimization discards the service-event identity.
        if (isset($payload['message']['pinned_message'])) {
            return new TelegramWebhookIngressResult(['ok' => true]);
        }

        $payload = $this->minimizedPayload($payload);
        $callbackResponse = $this->callbackResponse($payload);

        $inserted = DB::table('telegram_updates')->insertOrIgnore([
            'update_id' => (int) $validated['update_id'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $update = TelegramUpdate::query()->where('update_id', (int) $validated['update_id'])->firstOrFail();
        $duplicate = $inserted !== 1;
        $jobEnqueuedAt = null;

        if ($update->processed_at === null && ! $update->hasActiveClaim()) {
            $jobEnqueuedAt = now();
            $this->dispatchProcessor($update->id, $jobEnqueuedAt->toISOString());
        }

        DogfoodTiming::log('dogfood.telegram.webhook_ack', [
            'telegram_update_id' => $update->id,
            'update_correlation_id' => DogfoodTiming::correlation($validated['update_id']),
            'ack_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            'job_enqueued_at' => $jobEnqueuedAt?->toISOString(),
            'duplicate' => $duplicate,
        ]);

        return new TelegramWebhookIngressResult($callbackResponse ?? ['ok' => true]);
    }

    private function minimizedPayload(array $payload): array
    {
        if (isset($payload['message']) && is_array($payload['message'])) {
            return ['update_id' => $payload['update_id'], 'message' => $this->minimizedMessage($payload['message'])];
        }

        $callback = $payload['callback_query'];

        return [
            'update_id' => $payload['update_id'],
            'callback_query' => [
                'id' => $callback['id'],
                '_acknowledged_via_webhook' => (bool) config('services.telegram.webhook_fast_callback_ack', false),
                'from' => Arr::only((array) $callback['from'], ['id', 'first_name', 'username']),
                'message' => [
                    'message_id' => Arr::get($callback, 'message.message_id'),
                    'chat' => Arr::only((array) Arr::get($callback, 'message.chat', []), ['id', 'type']),
                ],
                'data' => $callback['data'],
            ],
        ];
    }

    private function minimizedMessage(array $message): array
    {
        $minimized = Arr::only($message, ['message_id', 'text', 'caption']);
        $minimized['from'] = Arr::only((array) $message['from'], ['id', 'first_name', 'username']);
        $minimized['chat'] = Arr::only((array) $message['chat'], ['id', 'type']);

        $customEmoji = $this->customEmojiEntities($message);
        if ($customEmoji !== []) {
            $minimized['entities'] = $customEmoji;
        }

        $mediaKind = TelegramMessageMedia::kind($message);
        if ($mediaKind !== null) {
            $minimized['media_kind'] = $mediaKind;
        }

        $mediaGroupId = $message['media_group_id'] ?? null;
        if ((is_string($mediaGroupId) || is_int($mediaGroupId)) && trim((string) $mediaGroupId) !== '') {
            $minimized['media_group_id'] = trim((string) $mediaGroupId);
        }

        if (isset($message['photo']) && is_array($message['photo'])) {
            $minimized['photo'] = array_values(array_filter(array_map(
                fn (mixed $photo): ?array => $this->minimizedPhotoSize($photo),
                $message['photo']
            )));
        }
        if (isset($message['reply_to_message']['photo']) && is_array($message['reply_to_message']['photo'])) {
            $minimized['reply_to_message'] = [
                'photo' => array_values(array_filter(array_map(
                    fn (mixed $photo): ?array => $this->minimizedPhotoSize($photo),
                    $message['reply_to_message']['photo']
                ))),
            ];
        }
        if (is_int($message['reply_to_message']['message_id'] ?? null)) {
            $minimized['reply_to_message']['message_id'] = $message['reply_to_message']['message_id'];
        }

        return $minimized;
    }

    /**
     * Preserve custom emoji identity and UTF-16 boundaries for catalog content
     * editing, without retaining unrelated entities or the replied message.
     *
     * @return list<array{type: string, custom_emoji_id: string, offset?: int, length?: int}>
     */
    private function customEmojiEntities(array $message): array
    {
        $entities = [];

        foreach (['entities', 'caption_entities'] as $field) {
            foreach ((array) ($message[$field] ?? []) as $entity) {
                if (! is_array($entity) || ($entity['type'] ?? null) !== 'custom_emoji') {
                    continue;
                }

                $id = $entity['custom_emoji_id'] ?? null;
                if (is_string($id) && trim($id) !== '') {
                    $entities[] = ['type' => 'custom_emoji', 'custom_emoji_id' => trim($id)]
                        + Arr::only($entity, ['offset', 'length']);
                }
            }
        }

        return $entities;
    }

    /** @return array{file_id: string, file_size?: int, width?: int, height?: int}|null */
    private function minimizedPhotoSize(mixed $photo): ?array
    {
        if (! is_array($photo) || ! is_string($photo['file_id'] ?? null) || trim($photo['file_id']) === '') {
            return null;
        }

        $minimized = ['file_id' => trim($photo['file_id'])];
        foreach (['file_size', 'width', 'height'] as $field) {
            $value = $photo[$field] ?? null;
            if (is_int($value) && $value >= 0) {
                $minimized[$field] = $value;
            } elseif (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
                $minimized[$field] = (int) $value;
            }
        }

        return $minimized;
    }

    /** @return array{method: string, callback_query_id: string}|null */
    private function callbackResponse(array $payload): ?array
    {
        $callbackQuery = $payload['callback_query'] ?? null;
        if (! is_array($callbackQuery) || ! ($callbackQuery['_acknowledged_via_webhook'] ?? false)) {
            return null;
        }

        return [
            'method' => 'answerCallbackQuery',
            'callback_query_id' => (string) $callbackQuery['id'],
        ];
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
