<?php

declare(strict_types=1);

use App\Enums\CartStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\PublishSalesProofPost;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\TelegramUpdate;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Telegram\FakeTelegramBotClient;
use App\Services\Telegram\TelegramBotClient;
use App\Support\AdminDogfoodHttpRunner;
use App\Support\DogfoodExternalFixtures;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

    public function sendPhoto(int|string $chatId, string $photoContents, string $filename, ?string $caption = null, array $replyMarkup = [], array $options = []): void
    {
        $this->measure('POST', 'sendPhoto', fn () => parent::sendPhoto($chatId, $photoContents, $filename, $caption, $replyMarkup, $options));
    }

    public function sendPhotoReference(int|string $chatId, string $photoFileId, ?string $caption = null, array $replyMarkup = [], array $options = []): void
    {
        $this->measure('POST', 'sendPhoto', fn () => parent::sendPhotoReference($chatId, $photoFileId, $caption, $replyMarkup, $options));
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
$fakeTelegramOutbound = (bool) config('services.dogfood_timing.fake_telegram_outbound');
$runMode = $fakeTelegramOutbound ? 'synthetic' : 'real-external';
$app->bind(TelegramBotClient::class, fn () => $fakeTelegramOutbound
    ? new FakeTelegramBotClient
    : new MeasuredTelegramBotClient($collector));
config()->set('logging.default', 'stderr');
config()->set('logging.channels.stack.channels', ['stderr']);
Log::setDefaultDriver('stderr');
config()->set('services.dogfood_timing.log_channel', 'stderr');
config()->set('services.dogfood_timing.enabled', true);
$fixtures = DogfoodExternalFixtures::fromConfig();

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

$photoPayload = function (string $caption, string $photoFileId) use ($nextId, $adminChatId, $adminUserId): array {
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
        '--queue' => 'default',
        '--once' => true,
        '--stop-when-empty' => true,
    ]);
    $app->make(ConsoleKernel::class)->call('queue:work', [
        '--queue' => 'telegram-outbound,notifications',
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

$blockScenario = function (string $fixtureScenario, string $feature, string $scenario, string $input) use (&$results, $fixtures): void {
    $results[] = [
        'feature' => $feature,
        'scenario' => $scenario,
        'input' => $input,
        'runs' => 0,
        'cold_ms' => null,
        'warm_avg_ms' => null,
        'slowest_ms' => null,
        'result' => 'blocked',
        'blocked_reason' => $fixtures->blockedReason($fixtureScenario),
        'required_fixtures' => $fixtures->missingFor($fixtureScenario),
        'runs_detail' => [],
    ];
};

$runFixtureScenario = function (string $fixtureScenario, string $feature, string $scenario, string $input, int $warmRuns, Closure $action) use ($fixtures, $blockScenario, $runScenario): void {
    if ($fixtures->blockedReason($fixtureScenario) !== null) {
        $blockScenario($fixtureScenario, $feature, $scenario, $input);

        return;
    }

    $runScenario($feature, $scenario, $input, $warmRuns, $action);
};

$adminRunner = null;
$authenticatedAdminRunner = function () use (&$adminRunner, $app, $fixtures): AdminDogfoodHttpRunner {
    if ($adminRunner instanceof AdminDogfoodHttpRunner) {
        return $adminRunner;
    }

    $adminRunner = new AdminDogfoodHttpRunner(
        $app->make(HttpKernel::class),
        $fixtures->adminEmail(),
        $fixtures->adminPassword(),
    );
    $adminRunner->login();

    return $adminRunner;
};

$configureStoreChannelFixture = function (bool $communityGateEnabled = false, bool $salesProofEnabled = false) use ($fixtures): StoreChannelSetting {
    return StoreChannelSetting::query()->updateOrCreate(
        ['singleton_key' => StoreChannelSetting::SINGLETON_KEY],
        [
            'channel_username' => $fixtures->storeChannelUsername(),
            'channel_link' => $fixtures->storeChannelLink(),
            'telegram_chat_id' => $fixtures->storeChannelChatId(),
            'community_gate_enabled' => $communityGateEnabled,
            'sales_proof_enabled' => $salesProofEnabled,
            'last_tested_at' => now(),
            'last_test_success' => true,
            'last_test_error' => null,
        ],
    );
};

$makeFixtureCart = function (int $telegramUserId, Product $cartProduct, int $quantity = 1): Cart {
    $customer = Customer::query()->updateOrCreate(
        ['telegram_id' => $telegramUserId],
        ['first_name' => 'Perf', 'username' => 'perf_dogfood']
    );
    Cart::query()->where('customer_id', $customer->id)->where('status', CartStatus::Active->value)->update(['status' => CartStatus::Abandoned->value]);
    $cart = Cart::query()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active->value, 'expires_at' => now()->addHour()]);
    CartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $cartProduct->id, 'quantity' => $quantity]);

    return $cart;
};

$makePakasirFixtureProduct = function () use ($fixtures, $category): Product {
    $amount = $fixtures->pakasirPendingAmount();
    $pakasirProduct = Product::query()->firstOrCreate(
        ['name' => 'Perf Dogfood Pakasir Fixture '.$amount],
        [
            'category_id' => $category->id,
            'description' => 'Pakasir dogfood transaction-detail fixture',
            'price_amount' => $amount,
            'currency' => 'IDR',
            'image_path' => null,
            'is_active' => true,
        ],
    );
    $pakasirProduct->forceFill([
        'category_id' => $category->id,
        'price_amount' => $amount,
        'stock' => 50,
        'reserved_stock' => 0,
        'is_active' => true,
    ])->save();

    return $pakasirProduct;
};

$runScenario('startup/cold boot', 'artisan kernel already booted baseline', 'script boot + app bootstrap', 0, fn () => true);

$runFixtureScenario(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION, 'Admin dashboard', 'authenticated login through HTTP kernel', 'GET /admin/login then POST /admin/login with CSRF', 0, function () use (&$adminRunner, $app, $fixtures) {
    $adminRunner = new AdminDogfoodHttpRunner(
        $app->make(HttpKernel::class),
        $fixtures->adminEmail(),
        $fixtures->adminPassword(),
    );

    return $adminRunner->login()->toScenarioResult();
});
$runFixtureScenario(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION, 'Admin mutation payment mode', 'invalid input through authenticated form', 'POST /admin/payment-mode invalid payment_mode', 1, function () use ($authenticatedAdminRunner) {
    return $authenticatedAdminRunner()
        ->submitForm('POST', '/admin/payment-mode', ['payment_mode' => 'invalid-dogfood-mode'])
        ->toScenarioResult();
});
$runFixtureScenario(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION, 'Admin mutation payment mode', 'valid manual mode through authenticated form', 'POST /admin/payment-mode payment_mode=manual', 1, function () use ($authenticatedAdminRunner) {
    return $authenticatedAdminRunner()
        ->submitForm('POST', '/admin/payment-mode', ['payment_mode' => 'manual'])
        ->toScenarioResult();
});
$runFixtureScenario(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION, 'Admin mutation category create', 'invalid input through authenticated form', 'POST /admin/categories blank category_name', 1, function () use ($authenticatedAdminRunner) {
    return $authenticatedAdminRunner()
        ->submitForm('POST', '/admin/categories', ['category_name' => '', 'category_description' => ''])
        ->toScenarioResult();
});
$runFixtureScenario(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION, 'Admin mutation category create', 'valid namespaced category through authenticated form', 'POST /admin/categories Perf Dogfood category', 1, function () use ($authenticatedAdminRunner) {
    $name = 'Perf Dogfood Category '.date('YmdHis').'-'.bin2hex(random_bytes(3));

    return $authenticatedAdminRunner()
        ->submitForm('POST', '/admin/categories', [
            'category_name' => $name,
            'category_description' => 'Safe performance dogfood category.',
        ])
        ->toScenarioResult();
});
$runFixtureScenario(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION, 'Admin mutation product create', 'invalid input through authenticated form', 'POST /admin/products blank product', 1, function () use ($authenticatedAdminRunner, $category) {
    return $authenticatedAdminRunner()
        ->submitForm('POST', '/admin/products', [
            'name' => '',
            'price_amount' => '',
            'category_id' => (string) $category->id,
            'description' => '',
            'features' => '',
            'specifications' => '',
        ])
        ->toScenarioResult();
});
$runFixtureScenario(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION, 'Admin mutation product create', 'valid namespaced product through authenticated form', 'POST /admin/products Perf Dogfood product', 1, function () use ($authenticatedAdminRunner, $category) {
    $name = 'Perf Dogfood Product '.date('YmdHis').'-'.bin2hex(random_bytes(3));

    return $authenticatedAdminRunner()
        ->submitForm('POST', '/admin/products', [
            'name' => $name,
            'price_amount' => '35000',
            'category_id' => (string) $category->id,
            'description' => 'Safe performance dogfood product.',
            'features' => "Namespaced benchmark item\nCreated through authenticated form",
            'specifications' => 'No production customer data',
        ])
        ->toScenarioResult();
});

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
$runFixtureScenario(DogfoodExternalFixtures::MANUAL_PAYMENT_PROOF, 'manual payment proof', 'photo proof happy path', 'real photo.file_id caption /payment latest invoice', 1, function () use ($fixtures, $makeActiveCart, $processPayload, $callbackPayload, $photoPayload, $latestManualOrderInvoice) {
    $makeActiveCart(1);
    $processPayload($callbackPayload('checkout'));
    $invoice = $latestManualOrderInvoice();
    $processPayload($photoPayload('/payment '.$invoice, $fixtures->telegramPhotoFileId()));
});
$runScenario('manual payment proof', 'invalid no photo path', 'text /payment', 1, fn () => $processPayload($messagePayload('/payment')));

$runFixtureScenario(DogfoodExternalFixtures::STORE_CHANNEL_SEND, 'Store Channel', 'test send to disposable channel', 'sendMessage to DOGFOOD_STORE_CHANNEL_CHAT_ID', 1, function () use ($app, $fixtures, $configureStoreChannelFixture) {
    $configureStoreChannelFixture(false, false);
    $app->make(TelegramBotClient::class)->sendMessage(
        $fixtures->storeChannelChatId(),
        'Dogfood Store Channel test '.date(DATE_ATOM),
    );
});
$runFixtureScenario(DogfoodExternalFixtures::STORE_CHANNEL_MEMBER_GATE, 'Store Channel community gate', 'known member can browse catalog', '/katalog from DOGFOOD_STORE_CHANNEL_MEMBER_USER_ID', 1, function () use ($fixtures, $configureStoreChannelFixture, $messagePayload, $processPayload) {
    $configureStoreChannelFixture(true, false);
    $processPayload($messagePayload('/katalog', $fixtures->storeChannelMemberUserId(), $fixtures->storeChannelMemberUserId()));
});
$runFixtureScenario(DogfoodExternalFixtures::STORE_CHANNEL_MEMBER_GATE, 'Store Channel community gate', 'known non-member is prompted', '/katalog from DOGFOOD_STORE_CHANNEL_NON_MEMBER_USER_ID', 1, function () use ($fixtures, $configureStoreChannelFixture, $messagePayload, $processPayload) {
    $configureStoreChannelFixture(true, false);
    $processPayload($messagePayload('/katalog', $fixtures->storeChannelNonMemberUserId(), $fixtures->storeChannelNonMemberUserId()));
});
$runFixtureScenario(DogfoodExternalFixtures::SALES_PROOF_POST, 'Sales Proof Post', 'publish paid order proof to Store Channel', 'paid manual order fixture', 1, function () use ($app, $adminUserId, $configureStoreChannelFixture, $makeFixtureCart, $product) {
    $configureStoreChannelFixture(false, true);
    $cart = $makeFixtureCart((int) $adminUserId, $product);
    $order = $app->make(OrderCheckoutService::class)->checkout($cart, 'manual', 'manual');
    $payment = $order->payments()->latest()->firstOrFail();
    $app->make(OrderStateMachine::class)->markPaymentPaid($payment);

    return ['status' => $app->make(ConsoleKernel::class)->call('queue:work', [
        '--queue' => 'notifications',
        '--once' => true,
        '--stop-when-empty' => true,
    ])];
});
$runFixtureScenario(DogfoodExternalFixtures::SALES_PROOF_POST, 'Sales Proof Post', 'manual retry failed proof to Store Channel', 'failed sales proof fixture', 1, function () use ($app, $adminUserId, $configureStoreChannelFixture, $makeFixtureCart, $product) {
    $configureStoreChannelFixture(false, true);
    $cart = $makeFixtureCart((int) $adminUserId, $product);
    $order = $app->make(OrderCheckoutService::class)->checkout($cart, 'manual', 'manual');
    $payment = $order->payments()->latest()->firstOrFail();
    $paidOrder = $app->make(OrderStateMachine::class)->markPaymentPaid($payment);
    SalesProofPost::query()->updateOrCreate(
        ['order_id' => $paidOrder->id],
        ['status' => SalesProofPost::STATUS_FAILED, 'attempts' => 3, 'last_error' => 'dogfood retry fixture'],
    );

    $app->call([new PublishSalesProofPost($paidOrder->id, true, now()->toISOString()), 'handle']);
});
$runFixtureScenario(DogfoodExternalFixtures::PAKASIR_RECONCILIATION, 'Pakasir transaction-detail', 'reconcile real pending transaction fixture', 'pakasir:reconcile against DOGFOOD_PAKASIR_PENDING_ORDER_ID', 1, function () use ($app, $adminUserId, $fixtures, $makeFixtureCart, $makePakasirFixtureProduct) {
    $cart = $makeFixtureCart((int) $adminUserId, $makePakasirFixtureProduct());
    $order = $app->make(OrderCheckoutService::class)->checkout($cart, 'hosted', 'pakasir');
    $payment = $order->payments()->latest()->firstOrFail();
    $payment->update([
        'merchant_ref' => $fixtures->pakasirPendingOrderId(),
        'amount' => $fixtures->pakasirPendingAmount(),
    ]);
    $order->update(['total_amount' => $fixtures->pakasirPendingAmount()]);

    return ['status' => $app->make(ConsoleKernel::class)->call('pakasir:reconcile', ['--chunk' => 5])];
});
$runFixtureScenario(DogfoodExternalFixtures::CALLBACK_ANSWER, 'Telegram callback-answer', 'real callback query happy path', 'DOGFOOD_TELEGRAM_CALLBACK_QUERY_ID from live Telegram tap', 0, function () use ($fixtures, $callbackPayload, $processPayload) {
    $processPayload($callbackPayload('status', $fixtures->telegramCallbackQueryId()));
});

$runScenario('scheduled job orders:expire-pending', 'artisan command', 'orders:expire-pending', 1, function () use ($app) {
    return ['status' => $app->make(ConsoleKernel::class)->call('orders:expire-pending')];
});
$runFixtureScenario(DogfoodExternalFixtures::PAKASIR_RECONCILIATION, 'scheduled job pakasir:reconcile', 'artisan command against real pending fixture', 'pakasir:reconcile --chunk=5', 1, function () use ($app) {
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

$queueDepth = null;
$failedJobsCount = null;
if (Schema::hasTable('jobs')) {
    $queueDepth = DB::table('jobs')->count();
}
if (Schema::hasTable('failed_jobs')) {
    $failedJobsCount = DB::table('failed_jobs')->count();
}

$results[] = [
    'feature' => 'queue integrity',
    'scenario' => 'final jobs queue depth',
    'input' => 'jobs table count',
    'runs' => 1,
    'cold_ms' => null,
    'warm_avg_ms' => null,
    'slowest_ms' => null,
    'result' => $queueDepth === 0 ? 'ok' : 'error',
    'queue_depth' => $queueDepth,
    'runs_detail' => [],
];

$results[] = [
    'feature' => 'queue integrity',
    'scenario' => 'ops:check-failed-jobs --max=0',
    'input' => 'failed_jobs table count',
    'runs' => 1,
    'cold_ms' => null,
    'warm_avg_ms' => null,
    'slowest_ms' => null,
    'result' => $failedJobsCount === 0 ? 'ok' : 'error',
    'failed_jobs' => $failedJobsCount,
    'runs_detail' => [],
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
        $manualNotificationJobQueueWaitMs = null;
        $manualNotificationJobProcessingMs = null;

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

            if (($event['event'] ?? '') === 'dogfood.telegram.manual_checkout_notifications_job_picked_up') {
                $manualNotificationJobQueueWaitMs = $context['queue_wait_ms'] ?? null;
            }

            if (($event['event'] ?? '') === 'dogfood.telegram.manual_checkout_notifications_job_finished') {
                $manualNotificationJobProcessingMs = $context['handler_duration_ms'] ?? null;
            }
        }

        $dominantCandidates = [
            'queue_wait' => $queueWaitMs,
            'update_worker_processing' => $workerProcessingMs,
            'manual_notification_job_queue_wait' => $manualNotificationJobQueueWaitMs,
            'manual_notification_job_processing' => $manualNotificationJobProcessingMs,
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
            'update_worker_processing_ms' => $workerProcessingMs,
            'manual_notification_job_queue_wait_ms' => $manualNotificationJobQueueWaitMs,
            'manual_notification_job_processing_ms' => $manualNotificationJobProcessingMs,
            'dominant_step' => $dominantStep,
            'dominant_step_ms' => $dominantStep === null ? null : $dominantMs,
            'dominant_candidates' => $dominantCandidates,
            'steps' => $steps,
        ];
    }

    $manualCheckoutQrReport = [
        'scenario' => $result['feature'].' / '.$result['scenario'],
        'interpretation' => 'Compare queue_wait_ms, update_worker_processing_ms, manual_notification_job_queue_wait_ms, manual_notification_job_processing_ms, order_checkout, manual_qr_file_read, manual_customer_send_photo, manual_admin_send_message, and the wrapper steps before optimizing.',
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

$blockedScenarios = array_values(array_map(
    fn (array $result): array => [
        'feature' => $result['feature'],
        'scenario' => $result['scenario'],
        'blocked_reason' => $result['blocked_reason'] ?? null,
        'required_fixtures' => $result['required_fixtures'] ?? [],
    ],
    array_filter($results, fn (array $result): bool => ($result['result'] ?? null) === 'blocked'),
));

$output = [
    'started_at' => $startedAt,
    'finished_at' => date(DATE_ATOM),
    'run_mode' => [
        'mode' => $runMode,
        'synthetic' => $fakeTelegramOutbound,
        'fake_telegram_outbound' => $fakeTelegramOutbound,
        'description' => $fakeTelegramOutbound
            ? 'Synthetic run: Telegram outbound is handled by FakeTelegramBotClient; real external fixture scenarios are blocked when fixtures are absent.'
            : 'Real-external run: Telegram outbound uses the configured Telegram Bot API; real fixture scenarios are blocked when fixtures are absent.',
    ],
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
    'external_fixtures' => $fixtures->summary(),
    'blocked_scenarios' => $blockedScenarios,
    'final_queue_state' => [
        'jobs_depth' => $queueDepth,
        'failed_jobs' => $failedJobsCount,
        'passes_ops_check_failed_jobs_max_0' => $failedJobsCount === 0,
    ],
    'product_id' => $product->id,
    'category_id' => $category->id,
    'manual_checkout_qr_report' => $manualCheckoutQrReport,
    'admin_telegram_latency_report' => $adminTelegramLatencyReport,
    'results' => $results,
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
