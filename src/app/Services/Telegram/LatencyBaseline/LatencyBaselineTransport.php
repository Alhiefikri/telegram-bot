<?php

namespace App\Services\Telegram\LatencyBaseline;

use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramBotClient;
use App\Support\DogfoodTiming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Local-only HTTP boundary: no payload, chat identifier or credential is logged. */
final class LatencyBaselineTransport
{
    public static function install(): void
    {
        if (! app()->environment(['local', 'testing'])
            || ! config('services.dogfood_timing.fake_telegram_outbound')
            || config('database.default') !== 'pgsql'
            || config('queue.default') !== 'redis'
            || config('cache.default') !== 'redis'
            || preg_match('/baseline|test/i', (string) config('database.connections.pgsql.database')) !== 1) {
            throw new RuntimeException('Latency HTTP fixtures require the disposable local baseline.');
        }

        Http::preventStrayRequests();
        $rejected = [];
        Http::fake(['https://api.telegram.org/*' => function (Request $request) use (&$rejected) {
            $operation = basename(parse_url($request->url(), PHP_URL_PATH));
            $data = $request->data();
            if ($request->isMultipart()) {
                $data = array_column($data, 'contents', 'name');
            }
            $chat = (string) ($data['chat_id'] ?? '');
            $text = (string) ($data['text'] ?? $data['caption'] ?? '');
            if (isset($data['document']) && is_string($data['document'])) {
                $text .= $data['document'];
            }
            if (isset($data['document']) && is_resource($data['document'])) {
                $position = ftell($data['document']);
                $text .= stream_get_contents($data['document']);
                fseek($data['document'], $position);
            }
            preg_match_all('/(?:latency-|synthetic-baseline-password-)(\d+)-(\d+)(?:@example\.test)?/', $text, $matches, PREG_SET_ORDER);
            $credential = $matches !== [];
            $receiptLane = array_any(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), static fn (array $frame): bool => ($frame['class'] ?? null) === ReceiptDeliveryPipeline::class);
            $wrongRecipient = false;
            foreach ($matches as $match) {
                $wrongRecipient = $wrongRecipient || $chat !== (string) (8_500_000 + (int) $match[1]);
            }
            $fault = (string) (getenv('LATENCY_BASELINE_TELEGRAM_FAULT') ?: 'none');
            $status = 200;
            if ($credential && $fault === 'rejected' && ! isset($rejected[$chat])) {
                $rejected[$chat] = true;
                $status = 400;
            }
            $delay = (int) config('services.dogfood_timing.fake_telegram_latency_us', 0);
            if (getenv('LATENCY_BASELINE_ADMIN_DELAY_ONLY') === 'true'
                && $chat !== (string) config('services.telegram.admin_telegram_chat_id')) {
                $delay = 0;
            }
            $started = microtime(true);
            if ($delay > 0) {
                usleep($delay);
            }
            DogfoodTiming::log('dogfood.telegram.baseline_transport', [
                'operation' => $operation,
                'recipient_correlation_id' => DogfoodTiming::correlation($chat),
                'unit_bearing' => $credential,
                'receipt_lane' => $receiptLane,
                'unit_count' => count(array_unique(array_map(static fn (array $match): string => $match[1].':'.$match[2], $matches))),
                'content_digest' => hash('sha256', $operation.'|'.$chat.'|'.$text),
                'wrong_recipient' => $wrongRecipient,
                'status' => $status,
                'accepted' => $status === 200,
                'ambiguous' => $credential && $fault === 'ambiguous',
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'finished_at' => now()->toISOString(),
            ]);
            if ($credential && $fault === 'ambiguous') {
                throw new ConnectionException('Synthetic accepted send with lost acknowledgement');
            }
            if ($status !== 200) {
                return Http::response(['ok' => false, 'error_code' => $status, 'description' => 'Synthetic bounded transport failure'], $status);
            }
            if (in_array($operation, ['deleteMessage', 'answerCallbackQuery'], true)) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response(['ok' => true, 'result' => [
                'message_id' => random_int(100_000, 999_999),
                'chat' => ['id' => $chat],
                'photo' => [['file_id' => 'baseline-photo']],
                'document' => ['file_id' => 'baseline-document'],
                'text' => $text,
            ]]);
        }]);
        app()->instance(TelegramBotClient::class, new TelegramBotClient);
        $delay = (int) (getenv('LATENCY_BASELINE_PROVIDER_DELAY_MS') ?: 0);
        if ($delay > 0) {
            config()->set('services.klikqris.sandbox_enabled', true);
            config()->set('services.klikqris.sandbox_api_key', 'latency-baseline-fixture-key');
            config()->set('services.klikqris.sandbox_merchant_id', 'latency-baseline-fixture-merchant');
            $provider = new KlikQrisLatencyFixtureProvider;
            $provider->statusDelayMs = $delay;
            $provider->alwaysPending = true;
            app()->instance(KlikQrisProviderAdapter::class, $provider);
        }
    }
}
