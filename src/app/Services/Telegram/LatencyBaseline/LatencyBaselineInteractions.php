<?php

namespace App\Services\Telegram\LatencyBaseline;

use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramUpdate;
use App\Support\DogfoodTiming;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LatencyBaselineInteractions
{
    public function enqueue(array $samples, callable $awaitProvider): array
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Burst HTTP probes require APP_ENV=testing; use the disposable shell runner.');
        }
        $probes = [];
        foreach ($samples as $sample) {
            $order = Order::with('customer')->findOrFail($sample['order_id']);
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $probes[] = $this->receive($order->customer, 'order:'.$order->invoice_number, 'check')
                    + ['payment_correlation_id' => $sample['payment_correlation_id']];
            }
        }
        $awaitProvider();
        foreach ($samples as $sample) {
            $order = Order::with('customer')->findOrFail($sample['order_id']);
            $probes[] = $this->receive($order->customer, 'status:home', 'home')
                + ['payment_correlation_id' => $sample['payment_correlation_id']];
        }

        return $probes;
    }

    private function receive(Customer $customer, string $data, string $kind): array
    {
        $chat = (int) $customer->telegram_id;
        $updateId = random_int(1_000_000_000, 2_000_000_000);
        $payload = ['update_id' => $updateId, 'callback_query' => [
            'id' => 'baseline-'.$updateId,
            'from' => ['id' => $chat, 'is_bot' => false, 'first_name' => 'Burst Fixture'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'private']],
            'data' => $data,
        ]];
        $request = Request::create('/api/webhook/telegram', 'POST', $payload);
        $request->headers->set('X-Telegram-Bot-Api-Secret-Token', (string) config('services.telegram.webhook_secret'));
        $started = microtime(true);
        $response = app(Kernel::class)->handle($request);
        $ackMs = round((microtime(true) - $started) * 1000, 2);
        $body = json_decode($response->getContent(), true);
        $update = TelegramUpdate::where('update_id', $updateId)->firstOrFail();

        return [
            'id' => $update->id,
            'customer_id' => $customer->id,
            'kind' => $kind,
            'callback_ack_ms' => $ackMs,
            'callback_ack_valid' => $response->getStatusCode() === 200 && ($body['method'] ?? null) === 'answerCallbackQuery',
            'enqueued_at' => $started,
        ];
    }

    public function finish(array $probes, LatencyBaselineEventCollector $collector, callable $pump, int $timeout): array
    {
        if ($probes === []) {
            return [];
        }
        $deadline = microtime(true) + $timeout;
        do {
            $pump();
            $remaining = TelegramUpdate::whereIn('id', array_column($probes, 'id'))->whereNull('processed_at')->count();
            if ($remaining === 0) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        $pump();

        return array_map(function (array $probe) use ($collector): array {
            $update = TelegramUpdate::findOrFail($probe['id']);
            $pickup = $collector->first('dogfood.telegram.job_picked_up', DogfoodTiming::correlation($update->id));
            $finished = $collector->first('dogfood.telegram.handler_finished', DogfoodTiming::correlation($update->id));
            $home = DB::table('telegram_navigation_roots')->where('customer_id', $probe['customer_id'])->where('screen', 'home')->exists();
            $provider = array_find(
                $collector->matching('dogfood.telegram.baseline_provider_finished', $probe['payment_correlation_id']),
                static fn (array $e): bool => $e['source'] === 'worker',
            );
            $beforeProvider = isset($finished['context']['action_completed_at'], $provider['context']['finished_at'])
                ? strcmp($finished['context']['action_completed_at'], $provider['context']['finished_at']) < 0
                : null;

            return [
                'kind' => $probe['kind'],
                'callback_ack_ms' => $probe['callback_ack_ms'],
                'callback_ack_valid' => $probe['callback_ack_valid'],
                'queue_wait_ms' => $pickup['context']['queue_wait_ms'] ?? null,
                'handler_ms' => $finished['context']['handler_duration_ms'] ?? null,
                'completion_ms' => isset($finished['context']['action_completed_at']) ? round(CarbonImmutable::parse($finished['context']['action_completed_at'])->getTimestampMs() - $probe['enqueued_at'] * 1000, 2) : null,
                'processed' => $update->processed_at !== null,
                'home_rendered' => $home,
                'terminal_failed' => $update->terminal_failed_at !== null,
                'completed_before_provider_finished' => $beforeProvider,
            ];
        }, $probes);
    }
}
