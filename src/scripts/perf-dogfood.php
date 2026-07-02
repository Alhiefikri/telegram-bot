<?php

declare(strict_types=1);

use App\Jobs\ProcessTelegramUpdate;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

final class PerfTraceCollector
{
    public ?string $current = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $db = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $http = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $dogfood = [];

    public function begin(string $scenario): void
    {
        $this->current = $scenario;
        $this->db[$scenario] ??= [];
        $this->http[$scenario] ??= [];
        $this->dogfood[$scenario] ??= [];
    }

    public function end(): void
    {
        $this->current = null;
    }

    public function recordDb(string $sql, float $ms): void
    {
        if ($this->current === null) {
            return;
        }

        $this->db[$this->current][] = ['ms' => $ms, 'sql' => preg_replace('/\s+/', ' ', $sql)];
    }

    public function recordHttp(string $method, string $url, float $ms, ?int $status, ?string $error): void
    {
        if ($this->current === null) {
            return;
        }

        $this->http[$this->current][] = [
            'method' => $method,
            'url' => preg_replace('#/bot[^/]+/#', '/bot<redacted>/', $url),
            'ms' => $ms,
            'status' => $status,
            'error' => $error,
        ];
    }

    public function recordDogfoodLog(string $message, array $context): void
    {
        if ($this->current === null || ! str_starts_with($message, 'dogfood.')) {
            return;
        }

        $this->dogfood[$this->current][] = [
            'event' => $message,
            'context' => $context,
        ];
    }
}

final class MeasuredTelegramBotClient extends TelegramBotClient
{
    public function __construct(private readonly PerfTraceCollector $collector) {}

    public function getChat(int|string $chatId): array
    {
        return $this->measure('POST', 'getChat', fn () => parent::getChat($chatId));
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->measure('POST', 'getChatMember', fn () => parent::getChatMember($chatId, $userId));
    }

    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = []): array
    {
        return $this->measure('POST', 'sendMessage', fn () => parent::sendMessage($chatId, $text, $replyMarkup));
    }

    public function sendPhoto(int|string $chatId, string $photoContents, string $filename, ?string $caption = null, array $replyMarkup = []): void
    {
        $this->measure('POST', 'sendPhoto', fn () => parent::sendPhoto($chatId, $photoContents, $filename, $caption, $replyMarkup));
    }

    public function sendPhotoReference(int|string $chatId, string $photoFileId, ?string $caption = null, array $replyMarkup = []): void
    {
        $this->measure('POST', 'sendPhoto', fn () => parent::sendPhotoReference($chatId, $photoFileId, $caption, $replyMarkup));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->measure('POST', 'answerCallbackQuery', fn () => parent::answerCallbackQuery($callbackQueryId, $text));
    }

    public function deleteMessage(int|string $chatId, int|string $messageId): void
    {
        $this->measure('POST', 'deleteMessage', fn () => parent::deleteMessage($chatId, $messageId));
    }

    /** @template T */
    private function measure(string $method, string $telegramMethod, Closure $call): mixed
    {
        $url = rtrim((string) config('services.telegram.api_base_url', 'https://api.telegram.org'), '/')
            .'/bot'.config('services.telegram.token').'/'.$telegramMethod;
        $start = hrtime(true);
        try {
            $result = $call();
            $this->collector->recordHttp($method, $url, (hrtime(true) - $start) / 1_000_000, null, null);

            return $result;
        } catch (Throwable $e) {
            $status = method_exists($e, 'response') ? $e->response?->status() : null;
            $this->collector->recordHttp($method, $url, (hrtime(true) - $start) / 1_000_000, $status, $e::class.': '.$e->getMessage());
            throw $e;
        }
    }
}

$collector = new PerfTraceCollector;
$app->singleton(PerfTraceCollector::class, fn () => $collector);
$app->bind(TelegramBotClient::class, fn () => new MeasuredTelegramBotClient($collector));
config()->set('services.dogfood_timing.enabled', true);

DB::listen(function ($query) use ($collector): void {
    $collector->recordDb($query->sql, (float) $query->time);
});

Event::listen(MessageLogged::class, function (MessageLogged $event) use ($collector): void {
    $collector->recordDogfoodLog($event->message, $event->context);
});

$startedAt = date(DATE_ATOM);
$adminChatId = trim((string) config('services.telegram.admin_telegram_chat_id'));
$adminUserId = trim((string) config('services.telegram.admin_user_id'));
$tokenConfigured = trim((string) config('services.telegram.token')) !== '';
$secret = (string) config('services.telegram.webhook_secret');

if ($adminChatId === '' || $adminUserId === '') {
    fwrite(STDERR, "TELEGRAM_ADMIN_CHAT_ID and TELEGRAM_ADMIN_USER_ID are required for real Telegram dogfooding.\n");
    exit(2);
}

if (! $tokenConfigured) {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN is required for real Telegram dogfooding.\n");
    exit(2);
}

if (! Schema::hasTable('telegram_updates')) {
    fwrite(STDERR, "Database is not migrated. Run migrations before this script.\n");
    exit(2);
}

$category = Category::query()->firstOrCreate(
    ['slug' => 'perf-dogfood'],
    ['name' => 'Perf Dogfood', 'description' => 'Performance dogfood category', 'sort_order' => 999, 'is_active' => true]
);

$product = Product::query()->firstOrCreate(
    ['name' => 'Perf Dogfood Product'],
    [
        'category_id' => $category->id,
        'description' => "Akun test performance dogfood\nEmail: perf@example.test\nPassword: secret",
        'price_amount' => 35000,
        'currency' => 'IDR',
        'stock' => 500,
        'reserved_stock' => 0,
        'image_path' => null,
        'is_active' => true,
    ]
);
$product->forceFill(['category_id' => $category->id, 'stock' => 500, 'reserved_stock' => 0, 'is_active' => true])->save();

$unavailableProduct = Product::query()->firstOrCreate(
    ['name' => 'Perf Dogfood Empty Product'],
    [
        'category_id' => $category->id,
        'description' => 'Unavailable dogfood product',
        'price_amount' => 99999,
        'currency' => 'IDR',
        'stock' => 0,
        'reserved_stock' => 0,
        'image_path' => null,
        'is_active' => true,
    ]
);
$unavailableProduct->forceFill(['category_id' => $category->id, 'stock' => 0, 'reserved_stock' => 0, 'is_active' => true])->save();

ManualPaymentSetting::query()->updateOrCreate(
    ['is_active' => true],
    [
        'payment_mode' => 'manual',
        'admin_contact_url' => null,
        'admin_username' => null,
        'admin_telegram_chat_id' => $adminChatId,
        'instructions' => 'Perf dogfood manual payment instructions.',
    ]
);

$nextUpdateId = (int) max((int) (TelegramUpdate::query()->max('update_id') ?? 0), random_int(1000000, 9000000));

$nextId = function () use (&$nextUpdateId): int {
    $nextUpdateId++;

    return $nextUpdateId;
};

$messagePayload = function (string $text, ?int $chatId = null, ?int $fromId = null, array $extraMessage = []) use ($nextId, $adminChatId, $adminUserId): array {
    $id = $nextId();

    return [
        'update_id' => $id,
        'message' => array_replace_recursive([
            'message_id' => $id,
            'from' => ['id' => $fromId ?? (int) $adminUserId, 'is_bot' => false, 'first_name' => 'Perf', 'username' => 'perf_dogfood'],
            'chat' => ['id' => $chatId ?? (int) $adminChatId, 'type' => 'private'],
            'date' => time(),
            'text' => $text,
        ], $extraMessage),
    ];
};

$photoPayload = function (string $caption, string $photoFileId = 'AgACAgUAAxkBAAIBperfDogfoodPhoto') use ($nextId, $adminChatId, $adminUserId): array {
    $id = $nextId();

    return [
        'update_id' => $id,
        'message' => [
            'message_id' => $id,
            'from' => ['id' => (int) $adminUserId, 'is_bot' => false, 'first_name' => 'Perf', 'username' => 'perf_dogfood'],
            'chat' => ['id' => (int) $adminChatId, 'type' => 'private'],
            'date' => time(),
            'caption' => $caption,
            'photo' => [
                ['file_id' => $photoFileId.'small', 'width' => 90, 'height' => 90, 'file_size' => 1000],
                ['file_id' => $photoFileId.'large', 'width' => 1280, 'height' => 720, 'file_size' => 250000],
            ],
        ],
    ];
};

$callbackPayload = function (string $data, ?string $callbackId = null) use ($nextId, $adminChatId, $adminUserId): array {
    $id = $nextId();

    return [
        'update_id' => $id,
        'callback_query' => [
            'id' => $callbackId ?? ('perf-callback-'.$id),
            'from' => ['id' => (int) $adminUserId, 'is_bot' => false, 'first_name' => 'Perf', 'username' => 'perf_dogfood'],
            'message' => ['message_id' => $id, 'chat' => ['id' => (int) $adminChatId, 'type' => 'private'], 'date' => time()],
            'data' => $data,
        ],
    ];
};

$makeActiveCart = function (int $quantity = 1) use ($adminUserId, $product): void {
    $customer = Customer::query()->updateOrCreate(
        ['telegram_id' => (int) $adminUserId],
        ['first_name' => 'Perf', 'username' => 'perf_dogfood']
    );
    Cart::query()->where('customer_id', $customer->id)->where('status', 'active')->update(['status' => 'abandoned']);
    $cart = Cart::query()->create(['customer_id' => $customer->id, 'status' => 'active', 'expires_at' => now()->addHour()]);
    CartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);
};

$latestManualOrderInvoice = function () use ($adminUserId): string {
    $customer = Customer::query()->where('telegram_id', (int) $adminUserId)->first();

    return (string) Order::query()
        ->where('customer_id', $customer?->id)
        ->where('payment_status', 'pending')
        ->latest('id')
        ->value('invoice_number');
};

$processPayload = function (array $payload) use ($app): void {
    $update = TelegramUpdate::query()->create([
        'update_id' => (int) $payload['update_id'],
        'payload' => $payload,
        'created_at' => now(),
    ]);

    ProcessTelegramUpdate::dispatch($update->id, now()->toISOString());
    $app->make(ConsoleKernel::class)->call('queue:work', [
        '--once' => true,
        '--stop-when-empty' => true,
    ]);
};

$httpWebhook = function (array $payload, ?string $overrideSecret = null) use ($app, $secret): array {
    $request = Request::create('/api/webhook/telegram', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $overrideSecret ?? $secret,
    ], json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $app->make(HttpKernel::class)->handle($request);

    return ['status' => $response->getStatusCode(), 'body' => $response->getContent()];
};

$drainDefaultQueue = function () use ($app): void {
    $app->make(ConsoleKernel::class)->call('queue:work', [
        '--stop-when-empty' => true,
    ]);
};

$results = [];

$runScenario = function (string $feature, string $scenario, string $input, int $warmRuns, Closure $action) use (&$results, $collector): void {
    $runOne = function (string $label) use ($feature, $scenario, $action, $collector): array {
        $collector->begin($feature.' / '.$scenario.' / '.$label);
        $memoryBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);
        $start = hrtime(true);
        $result = 'ok';
        $error = null;
        try {
            $value = $action();
            if (is_array($value) && isset($value['status'])) {
                $result = 'http '.$value['status'];
            }
        } catch (Throwable $e) {
            $result = 'error';
            $error = $e::class.': '.$e->getMessage();
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $memoryAfter = memory_get_usage(true);
        $peakAfter = memory_get_peak_usage(true);
        $key = $feature.' / '.$scenario.' / '.$label;
        $dbEvents = $collector->db[$key] ?? [];
        $httpEvents = $collector->http[$key] ?? [];
        $dogfoodEvents = $collector->dogfood[$key] ?? [];
        $collector->end();

        return [
            'label' => $label,
            'ms' => round($elapsed, 3),
            'result' => $result,
            'error' => $error,
            'db_count' => count($dbEvents),
            'db_ms' => round(array_sum(array_column($dbEvents, 'ms')), 3),
            'db_slowest_ms' => round(max(array_column($dbEvents, 'ms') ?: [0]), 3),
            'http_count' => count($httpEvents),
            'http_ms' => round(array_sum(array_column($httpEvents, 'ms')), 3),
            'http_slowest_ms' => round(max(array_column($httpEvents, 'ms') ?: [0]), 3),
            'http_events' => $httpEvents,
            'dogfood_events' => $dogfoodEvents,
            'memory_before_bytes' => $memoryBefore,
            'memory_after_bytes' => $memoryAfter,
            'memory_delta_bytes' => $memoryAfter - $memoryBefore,
            'peak_delta_bytes' => $peakAfter - $peakBefore,
        ];
    };

    $runs = [$runOne('cold')];
    for ($i = 1; $i <= $warmRuns; $i++) {
        $runs[] = $runOne('warm-'.$i);
    }

    $warm = array_values(array_filter($runs, fn ($run) => str_starts_with($run['label'], 'warm-')));
    $results[] = [
        'feature' => $feature,
        'scenario' => $scenario,
        'input' => $input,
        'runs' => count($runs),
        'cold_ms' => $runs[0]['ms'],
        'warm_avg_ms' => $warm === [] ? null : round(array_sum(array_column($warm, 'ms')) / count($warm), 3),
        'slowest_ms' => round(max(array_column($runs, 'ms')), 3),
        'result' => implode(',', array_unique(array_map(fn ($run) => $run['result'], $runs))),
        'runs_detail' => $runs,
    ];
};

$runScenario('startup/cold boot', 'artisan kernel already booted baseline', 'script boot + app bootstrap', 0, fn () => true);

$runScenario('Telegram webhook intake', 'happy path through HTTP kernel', '/start JSON update', 2, fn () => $httpWebhook($messagePayload('/start')));
$duplicatePayload = $messagePayload('/start');
$runScenario('Telegram webhook intake', 'duplicate update repeated usage', 'same update_id twice', 2, function () use ($httpWebhook, $duplicatePayload) {
    $first = $httpWebhook($duplicatePayload);
    $second = $httpWebhook($duplicatePayload);

    return ['status' => $first['status'].'/'.$second['status']];
});
$runScenario('Telegram webhook intake', 'invalid secret error path', '/start with bad secret', 2, fn () => $httpWebhook($messagePayload('/start'), 'bad-secret'));
$runScenario('Telegram webhook intake', 'unsupported update error path', 'edited_message only', 2, fn () => $httpWebhook(['update_id' => random_int(9100000, 9900000), 'edited_message' => []]));

$drainDefaultQueue();

$commandScenarios = [
    ['/start', 'happy path', fn () => $messagePayload('/start')],
    ['/katalog', 'happy path', fn () => $messagePayload('/katalog')],
    ['/menu', 'happy path', fn () => $messagePayload('/menu')],
    ['/support', 'happy path', fn () => $messagePayload('/support')],
    ['/akun', 'happy path', fn () => $messagePayload('/akun')],
    ['/cari', 'invalid input missing keyword', fn () => $messagePayload('/cari')],
    ['/cari keyword', 'happy path', fn () => $messagePayload('/cari Perf')],
    ['/cari none', 'not found path', fn () => $messagePayload('/cari definitely-not-found-'.random_int(1, 999999))],
    ['/batal', 'repeated usage', fn () => $messagePayload('/batal')],
    ['/status', 'happy or empty path', fn () => $messagePayload('/status')],
    ['/riwayat', 'happy or empty path', fn () => $messagePayload('/riwayat')],
    ['/order', 'invalid input missing invoice', fn () => $messagePayload('/order')],
    ['/order invoice', 'not found path', fn () => $messagePayload('/order INV-NOTFOUND')],
    ['unknown command', 'invalid input', fn () => $messagePayload('/tidak_ada')],
    ['/broadcast', 'invalid input missing message', fn () => $messagePayload('/broadcast')],
    ['/broadcast message', 'admin operator happy path', fn () => $messagePayload('/broadcast Perf dogfood broadcast '.date('H:i:s'))],
];

foreach ($commandScenarios as [$feature, $scenario, $factory]) {
    $runScenario($feature, $scenario, $feature, 1, fn () => $processPayload($factory()));
}

$callbackScenarios = [
    ['callback katalog', 'happy path', 'katalog', fn () => null],
    ['callback back:categories', 'happy path', 'back:categories', fn () => null],
    ['callback category', 'happy path', 'category:'.$category->id, fn () => null],
    ['callback category missing', 'invalid input', 'category:99999999', fn () => null],
    ['callback product', 'happy path', 'product:'.$product->id, fn () => null],
    ['callback product missing', 'invalid input', 'product:99999999', fn () => null],
    ['callback buy', 'stateful quantity prompt', 'buy:'.$product->id, fn () => null],
    ['callback buy unavailable', 'invalid stock path', 'buy:'.$unavailableProduct->id, fn () => null],
    ['callback qty', 'add cart happy path', 'qty:'.$product->id.':1', fn () => null],
    ['callback status', 'happy or empty path', 'status', fn () => null],
    ['callback order', 'not found path', 'order:INV-NOTFOUND', fn () => null],
    ['callback batal', 'repeated usage', 'batal', fn () => null],
    ['callback balance', 'placeholder path', 'balance', fn () => null],
    ['callback unknown', 'invalid input', 'nope', fn () => null],
];

foreach ($callbackScenarios as [$feature, $scenario, $data, $setup]) {
    $runScenario($feature, $scenario, $data, 1, function () use ($setup, $processPayload, $callbackPayload, $data) {
        $setup();
        $processPayload($callbackPayload($data));
    });
}

$runScenario('conversation quantity text', 'stateful happy path', 'buy callback then message "1"', 1, function () use ($processPayload, $callbackPayload, $messagePayload, $product) {
    $processPayload($callbackPayload('buy:'.$product->id));
    $processPayload($messagePayload('1'));
});
$runScenario('conversation quantity text', 'invalid input keeps session', 'buy callback then message "abc"', 1, function () use ($processPayload, $callbackPayload, $messagePayload, $product) {
    $processPayload($callbackPayload('buy:'.$product->id));
    $processPayload($messagePayload('abc'));
});
$runScenario('checkout manual', 'happy path file/media QR send', 'active cart then checkout callback', 1, function () use ($makeActiveCart, $processPayload, $callbackPayload) {
    $makeActiveCart(1);
    $processPayload($callbackPayload('checkout'));
});
$runScenario('checkout manual', 'invalid empty cart path', 'checkout callback without cart', 1, fn () => $processPayload($callbackPayload('checkout')));
$runScenario('manual payment proof', 'photo proof happy path', 'photo caption /payment latest invoice', 1, function () use ($makeActiveCart, $processPayload, $callbackPayload, $photoPayload, $latestManualOrderInvoice) {
    $makeActiveCart(1);
    $processPayload($callbackPayload('checkout'));
    $invoice = $latestManualOrderInvoice();
    $processPayload($photoPayload('/payment '.$invoice));
});
$runScenario('manual payment proof', 'invalid no photo path', 'text /payment', 1, fn () => $processPayload($messagePayload('/payment')));

$runScenario('scheduled job orders:expire-pending', 'artisan command', 'orders:expire-pending', 1, function () use ($app) {
    return ['status' => $app->make(ConsoleKernel::class)->call('orders:expire-pending')];
});
$runScenario('scheduled job pakasir:reconcile', 'artisan command error or empty path', 'pakasir:reconcile --chunk=5', 1, function () use ($app) {
    return ['status' => $app->make(ConsoleKernel::class)->call('pakasir:reconcile', ['--chunk' => 5])];
});
$runScenario('health check', 'artisan command', 'ops:health --db --queue-schema', 1, function () use ($app) {
    return ['status' => $app->make(ConsoleKernel::class)->call('ops:health', ['--db' => true, '--queue-schema' => true])];
});

$burstStart = hrtime(true);
$burstErrors = 0;
$burstMemoryBefore = memory_get_usage(true);
for ($i = 0; $i < 10; $i++) {
    try {
        $processPayload($messagePayload('/menu'));
    } catch (Throwable) {
        $burstErrors++;
    }
}
$burstMs = (hrtime(true) - $burstStart) / 1_000_000;
$results[] = [
    'feature' => 'concurrency/burst light',
    'scenario' => '10 sequential updates in one process',
    'input' => '/menu x10',
    'runs' => 10,
    'cold_ms' => null,
    'warm_avg_ms' => round($burstMs / 10, 3),
    'slowest_ms' => null,
    'result' => $burstErrors === 0 ? 'ok' : 'errors '.$burstErrors,
    'runs_detail' => [[
        'label' => 'burst-total',
        'ms' => round($burstMs, 3),
        'memory_delta_bytes' => memory_get_usage(true) - $burstMemoryBefore,
        'errors' => $burstErrors,
    ]],
];

$manualCheckoutQrReport = null;
foreach ($results as $result) {
    if ($result['feature'] !== 'checkout manual' || $result['scenario'] !== 'happy path file/media QR send') {
        continue;
    }

    $runs = [];
    foreach ($result['runs_detail'] as $run) {
        $steps = [];
        $queueWaitMs = null;
        $workerProcessingMs = null;

        foreach ($run['dogfood_events'] ?? [] as $event) {
            $context = $event['context'] ?? [];
            if (($event['event'] ?? '') === 'dogfood.telegram.checkout_step') {
                $steps[(string) ($context['step'] ?? 'unknown')] = [
                    'duration_ms' => $context['duration_ms'] ?? null,
                    'db_query_count' => $context['db_query_count'] ?? null,
                    'db_query_total_ms' => $context['db_query_total_ms'] ?? null,
                    'db_query_slowest_ms' => $context['db_query_slowest_ms'] ?? null,
                    'status' => $context['status'] ?? null,
                ];
            }

            if (($event['event'] ?? '') === 'dogfood.telegram.job_picked_up') {
                $queueWaitMs = $context['queue_wait_ms'] ?? null;
            }

            if (($event['event'] ?? '') === 'dogfood.telegram.handler_finished') {
                $workerProcessingMs = $context['handler_duration_ms'] ?? null;
            }
        }

        $dominantCandidates = [
            'queue_wait' => $queueWaitMs,
            'worker_processing' => $workerProcessingMs,
            'order_checkout' => $steps['order_checkout']['duration_ms'] ?? null,
            'manual_qr_file_read' => $steps['manual_qr_file_read']['duration_ms'] ?? null,
            'manual_customer_send_photo' => $steps['manual_customer_send_photo']['duration_ms'] ?? null,
            'manual_admin_send_message' => $steps['manual_admin_send_message']['duration_ms'] ?? null,
        ];

        $dominantStep = null;
        $dominantMs = -1;
        foreach ($dominantCandidates as $step => $durationMs) {
            if ($durationMs === null) {
                continue;
            }

            $durationMs = (float) $durationMs;
            if ($durationMs > $dominantMs) {
                $dominantMs = $durationMs;
                $dominantStep = $step;
            }
        }

        $runs[] = [
            'label' => $run['label'],
            'total_ms' => $run['ms'],
            'queue_wait_ms' => $queueWaitMs,
            'worker_processing_ms' => $workerProcessingMs,
            'dominant_step' => $dominantStep,
            'dominant_step_ms' => $dominantStep === null ? null : $dominantMs,
            'dominant_candidates' => $dominantCandidates,
            'steps' => $steps,
        ];
    }

    $manualCheckoutQrReport = [
        'scenario' => $result['feature'].' / '.$result['scenario'],
        'interpretation' => 'Compare queue_wait_ms, worker_processing_ms, order_checkout, manual_qr_file_read, manual_customer_send_photo, manual_admin_send_message, and the wrapper steps before optimizing.',
        'runs' => $runs,
    ];

    break;
}

$adminTelegramLatencyReport = [];
foreach ($results as $result) {
    foreach ($result['runs_detail'] as $run) {
        foreach ($run['dogfood_events'] ?? [] as $event) {
            if (($event['event'] ?? '') !== 'dogfood.telegram.admin_request_finished') {
                continue;
            }

            $context = $event['context'] ?? [];
            $adminTelegramLatencyReport[] = [
                'scenario' => $result['feature'].' / '.$result['scenario'],
                'run_label' => $run['label'] ?? null,
                'action' => $context['action'] ?? null,
                'request_duration_ms' => $context['request_duration_ms'] ?? null,
                'recipient_count' => $context['recipient_count'] ?? null,
                'sent' => $context['sent'] ?? null,
                'failed' => $context['failed'] ?? null,
                'skipped' => $context['skipped'] ?? null,
                'telegram_total_duration_ms' => $context['telegram_total_duration_ms'] ?? null,
                'per_recipient_telegram' => $context['per_recipient_telegram'] ?? [],
            ];
        }
    }
}

$output = [
    'started_at' => $startedAt,
    'finished_at' => date(DATE_ATOM),
    'environment' => [
        'php' => PHP_VERSION,
        'laravel' => app()->version(),
        'app_env' => app()->environment(),
        'db_connection' => config('database.default'),
        'queue_connection' => config('queue.default'),
        'telegram_api_base_url' => config('services.telegram.api_base_url'),
        'telegram_token_configured' => $tokenConfigured,
        'admin_chat_configured' => $adminChatId !== '',
        'admin_user_configured' => $adminUserId !== '',
    ],
    'product_id' => $product->id,
    'category_id' => $category->id,
    'manual_checkout_qr_report' => $manualCheckoutQrReport,
    'admin_telegram_latency_report' => $adminTelegramLatencyReport,
    'results' => $results,
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
