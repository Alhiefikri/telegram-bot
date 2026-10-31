<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\VerifyProviderPaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramUiIcon;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeSubmission;
use App\Services\Telegram\LatencyBaseline\KlikQrisLatencyFixtureProvider;
use App\Support\DogfoodTiming;
use App\Support\QueueTopology;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

// Private runtime files hold synthetic lookup handles and observations; the
// separate, sanitized evidence spool survives until the host has persisted it.
final class MigrationProbe
{
    private const BOUNDARIES = ['ingress', 'ingress_ack', 'interaction_queued', 'pickup', 'screen_ack', 'checkout_ready', 'provider_paid', 'paid_observed', 'paid_committed', 'receipt_queued', 'receipt_pickup', 'credential_ack', 'group_complete'];
    private const SCENARIOS = ['home_catalog', 'catalog_plans', 'plan_detail', 'back', 'home', 'quantity', 'orders', 'balance', 'checkout', 'paid_delivery'];
    private static string $directory;
    private static ?string $active = null;
    private static float $dbMs = 0;
    private static bool $instructionJob = false;
    private static ?string $activeUpdate = null;
    private static ?string $jobKind = null;
    private static ?string $jobToken = null;
    private static bool $queryActive = false;
    private static array $processes = [];
    private static array $clients = [];
    private static float $pacingMs = 0;
    private static int $pacingWaits = 0;

    public static function main(): void
    {
        ini_set('display_errors', '0');
        $internal = ($GLOBALS['argv'][1] ?? '') === '--internal';
        ob_start();
        try {
            if (($GLOBALS['argv'][1] ?? '') === '--internal') {
                $job = json_decode((string) file_get_contents($GLOBALS['argv'][2]), true, 512, JSON_THROW_ON_ERROR);
                self::$directory = dirname($GLOBALS['argv'][2]);
                self::boot();
                if ($job['kind'] === 'worker') {
                    self::worker($job);
                } else {
                    self::attempt($job);
                }
                ob_end_clean();
                exit(0);
            }
            $input = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            self::validate($input);
            self::$directory = sys_get_temp_dir().'/migration-p04-'.bin2hex(random_bytes(8));
            if (! mkdir(self::$directory, 0700)) {
                throw new RuntimeException('probe_error');
            }
            if ($input['mode'] === 'batch') {
                self::initializeEvidence($input['evidence_path']);
            }
            self::boot();
            $result = match ($input['mode']) {
                'seed' => ['manifest' => self::seed($input['dataset'])],
                'admit_seed' => ['manifest' => self::admitSeed($input['dataset'], $input['logical_sha256']), 'admitted' => true],
                'batch' => self::batch($input),
            };
            self::stopWorkers();
            self::stopClients();
            self::removeRuntime();
            ob_end_clean();
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        } catch (Throwable $exception) {
            if ($internal) {
                file_put_contents(self::$directory.'/child-error-'.getmypid().'.json',
                    json_encode(['error_type' => get_class($exception),
                        'source_file' => basename($exception->getFile()),
                        'source_line' => $exception->getLine()], JSON_THROW_ON_ERROR));
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                exit(1);
            }
            self::stopClients();
            $childErrors = array_map(static fn (string $path): array => json_decode(
                (string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
                isset(self::$directory) ? (glob(self::$directory.'/child-error-*.json') ?: []) : []);
            self::stopWorkers();
            self::removeRuntime();
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo json_encode(['error_code' => self::errorCode($exception),
                'error_type' => get_class($exception), 'source_file' => basename($exception->getFile()),
                'source_line' => $exception->getLine(), 'child_errors' => $childErrors,
                'evidence_path' => $input['evidence_path'] ?? null], JSON_THROW_ON_ERROR)."\n";
            exit(1);
        }
    }

    private static function validate(mixed $input): void
    {
        if (! is_array($input) || ! in_array($input['mode'] ?? null, ['seed', 'admit_seed', 'batch'], true)
            || ! in_array($input['dataset'] ?? null, ['small', 'large'], true)) {
            throw new InvalidArgumentException('probe_error');
        }
        if ($input['mode'] === 'seed') {
            return;
        }
        if ($input['mode'] === 'admit_seed') {
            if (! is_string($input['logical_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $input['logical_sha256']) !== 1) {
                throw new InvalidArgumentException('probe_error');
            }
            return;
        }
        foreach (['scenario' => self::SCENARIOS, 'users' => [1, 5, 10], 'traffic' => ['distinct', 'same_chat'], 'temperature' => ['cold', 'warm'], 'background' => ['idle', 'representative'], 'profile' => ['current', 'normalized']] as $key => $values) {
            if (! in_array($input[$key] ?? null, $values, true)) {
                throw new InvalidArgumentException('probe_error');
            }
        }
        foreach (['batch', 'samples'] as $key) {
            if (! is_int($input[$key] ?? null) || $input[$key] < 1 || $input[$key] > 100000) {
                throw new InvalidArgumentException('probe_error');
            }
        }
        if (! is_bool($input['warmup'] ?? null)) {
            throw new InvalidArgumentException('probe_error');
        }
        if (! is_string($input['evidence_path'] ?? null)
            || preg_match('~\A/var/www/html/storage/app/private/p04-evidence/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.jsonl\z~D', $input['evidence_path']) !== 1) {
            throw new InvalidArgumentException('probe_error');
        }
    }

    private static function initializeEvidence(string $path): void
    {
        if (! function_exists('posix_setsid') || ! function_exists('posix_getpgrp')
            || (posix_getpgrp() !== getmypid() && posix_setsid() === -1)) {
            throw new RuntimeException('probe_error');
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('probe_error');
        }
        $spool = fopen($path, 'x+b');
        if ($spool === false) {
            throw new RuntimeException('probe_error');
        }
        fclose($spool);
        chmod($path, 0600);
        $marker = fopen($path.'.pid', 'x+b');
        if ($marker === false) {
            throw new RuntimeException('probe_error');
        }
        try {
            chmod($path.'.pid', 0600);
            self::writeFlushed($marker, (string) getmypid()."\n");
        } finally {
            fclose($marker);
        }
    }

    private static function writeFlushed(mixed $stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('probe_error');
            }
            $offset += $written;
        }
        if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
            throw new RuntimeException('probe_error');
        }
    }

    private static function persistRecord(string $path, array $record): void
    {
        // Do not recreate a missing spool or remove it during runtime cleanup.
        $stream = fopen($path, 'r+b');
        if ($stream === false) {
            throw new RuntimeException('probe_error');
        }
        try {
            if (! flock($stream, LOCK_EX) || fseek($stream, 0, SEEK_END) !== 0) {
                throw new RuntimeException('probe_error');
            }
            self::writeFlushed($stream, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private static function recoverRecord(string $path, int $ordinal): ?array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('probe_error');
        }
        try {
            while (($line = fgets($stream)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (($record['attempt'] ?? null) === $ordinal) {
                    return $record;
                }
            }
        } finally {
            fclose($stream);
        }
        return null;
    }

    private static function accounted(array $events, string $kind, ?string $token): bool
    {
        return $token !== null && array_any($events, static fn (array $event): bool =>
            $event['boundary'] === 'db' && ($event['job_kind'] ?? null) === $kind && ($event['job_token'] ?? null) === $token);
    }

    private static function boot(): void
    {
        require '/var/www/html/vendor/autoload.php';
        $app = require '/var/www/html/bootstrap/app.php';
        $app->make(ConsoleKernel::class)->bootstrap();
        if (! $app->environment('testing') || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== '127.0.0.1'
            || config('database.connections.pgsql.database') !== 'disposable'
            || filled(config('database.connections.pgsql.url'))
            || config('queue.default') !== 'redis' || config('cache.default') !== 'redis') {
            throw new RuntimeException('probe_error');
        }
        foreach (['default', 'cache'] as $connection) {
            if (config('database.redis.'.$connection.'.host') !== '127.0.0.1'
                || filled(config('database.redis.'.$connection.'.url'))) {
                throw new RuntimeException('probe_error');
            }
        }
        config()->set([
            'services.telegram.token' => 'p04-synthetic-token',
            'services.telegram.webhook_secret' => 'p04-synthetic-webhook-secret',
            'services.telegram.api_base_url' => 'https://api.telegram.org',
            'services.telegram.admin_telegram_chat_id' => '8999999',
            'services.klikqris.sandbox_enabled' => true,
            'services.klikqris.sandbox_base_url' => 'https://klikqris.com/api/sandbox',
            'services.klikqris.sandbox_api_key' => 'p04-synthetic-key',
            'services.klikqris.sandbox_merchant_id' => 'p04-synthetic-merchant',
            'services.klikqris.status_max_attempts' => 1,
            'services.klikqris.status_retry_delay_ms' => 0,
            'services.dogfood_timing.enabled' => true,
            'services.dogfood_timing.log_channel' => null,
            'logging.default' => 'p04_null',
            'logging.channels.p04_null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
        ]);
        $provider = new class implements KlikQrisProviderAdapter
        {
            public bool $alwaysPending = true;
            public ?Carbon\CarbonImmutable $providerPaidAt = null;

            public function createTransaction(Payment $payment): App\Services\KlikQris\KlikQrisTransaction
            {
                return (new KlikQrisLatencyFixtureProvider)->createTransaction($payment);
            }

            public function transactionStatus(Payment $payment): App\Services\KlikQris\KlikQrisTransaction
            {
                $fixture = new KlikQrisLatencyFixtureProvider;
                $fixture->alwaysPending = $this->alwaysPending && ! Cache::get('p04:paid:'.DogfoodTiming::correlation($payment->id), false);
                $fixture->providerPaidAt = $this->providerPaidAt;
                return $fixture->transactionStatus($payment);
            }
        };
        app()->instance(KlikQrisProviderAdapter::class, $provider);
        app()->instance(App\Services\Telegram\TelegramBotClient::class,
            new App\Services\Telegram\TelegramBotClient);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/botp04-synthetic-token/*' => static function (Illuminate\Http\Client\Request $request) {
            $operation = basename((string) parse_url($request->url(), PHP_URL_PATH));
            if (! in_array($operation, ['sendMessage', 'sendPhoto', 'sendDocument', 'editMessageText', 'editMessageCaption', 'editMessageReplyMarkup', 'editMessageMedia', 'deleteMessage', 'answerCallbackQuery', 'sendChatAction', 'getMe', 'getChat', 'getCustomEmojiStickers'], true)) {
                throw new RuntimeException('transport_error');
            }
            $data = $request->data();
            if ($request->isMultipart()) {
                $data = array_column($data, 'contents', 'name');
            }
            $chat = (string) ($data['chat_id'] ?? '');
            if (self::$active !== null
                && in_array($operation, ['sendMessage', 'sendPhoto', 'sendDocument'], true)
                && Cache::get('p04:ambiguous-order:'.self::$active, false)) {
                // An accepted synthetic send loses its response. The real
                // pipeline/ledger must classify ambiguity, never the seed.
                throw new Illuminate\Http\Client\ConnectionException('Synthetic accepted send with lost acknowledgement');
            }
            $markup = $data['reply_markup'] ?? [];
            if (is_string($markup)) {
                $markup = json_decode($markup, true) ?? [];
            }
            $callbacks = [];
            foreach ($markup['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    if (isset($button['callback_data'])) {
                        $callbacks[] = $button['callback_data'];
                    }
                }
            }
            if ($chat !== '' && $callbacks !== []) {
                // Private Redis state is a synthetic client's current controls,
                // not benchmark evidence or a persisted request payload.
                Cache::put('p04:controls:'.DogfoodTiming::correlation($chat), $callbacks, 3600);
            }
            if (in_array($operation, ['deleteMessage', 'answerCallbackQuery', 'sendChatAction'], true)) {
                return Http::response(['ok' => true, 'result' => true]);
            }
            if ($operation === 'getCustomEmojiStickers') {
                return Http::response(['ok' => true, 'result' => []]);
            }
            return Http::response(['ok' => true, 'result' => [
                'message_id' => (int) ($data['message_id']
                    ?? (2000000000 + Cache::increment('p04:message-sequence:'.DogfoodTiming::correlation($chat)))),
                'chat' => ['id' => (int) $chat, 'type' => 'private'],
                'photo' => [['file_id' => 'p04-synthetic-photo']],
                'document' => ['file_id' => 'p04-synthetic-document'],
                'text' => '',
            ]]);
        }, 'https://klikqris.com/storage/sandbox/qris_baseline.png' => static fn () => Http::response(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true),
            200, ['Content-Type' => 'image/png'],
        )]);
        Event::listen(ResponseReceived::class, static function (ResponseReceived $event): void {
            if (! $event->response->successful() || $event->response->json('ok') !== true) {
                return;
            }
            $operation = basename((string) parse_url($event->request->url(), PHP_URL_PATH));
            if (self::$active !== null && in_array($operation, ['sendMessage', 'sendPhoto', 'sendDocument', 'editMessageText', 'editMessageCaption', 'editMessageMedia'], true)) {
                self::observe(self::$active, 'screen_ack', ['job_token' => self::$jobToken]);
                if (self::$instructionJob) {
                    self::observe(self::$active, 'checkout_ready', ['job_token' => self::$jobToken]);
                }
            }
        });
        DB::listen(static function (QueryExecuted $query): void {
            if (self::$queryActive) {
                self::$dbMs += (float) $query->time;
            }
        });
        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            self::$active = null;
            self::$instructionJob = false;
            self::$activeUpdate = null;
            self::$jobKind = null;
            self::$jobToken = bin2hex(random_bytes(8));
            self::$dbMs = 0;
            self::$queryActive = true;
            $payload = $event->job->payload();
            $command = $payload['data']['command'] ?? null;
            if (is_string($command) && in_array($payload['displayName'] ?? '', [App\Jobs\SendKlikQrisCheckoutInstructions::class, App\Jobs\SendTelegramReceipt::class, VerifyProviderPaymentStatus::class], true)) {
                $job = unserialize($command, ['allowed_classes' => [App\Jobs\SendKlikQrisCheckoutInstructions::class, App\Jobs\SendTelegramReceipt::class, VerifyProviderPaymentStatus::class]]);
                if ($job instanceof VerifyProviderPaymentStatus) {
                    $payment = Payment::query()->find($job->paymentId);
                    self::$active = $payment === null ? null : DogfoodTiming::correlation($payment->order_id);
                    self::$jobKind = 'verification';
                } elseif (isset($job->orderId)) {
                    self::$active = DogfoodTiming::correlation($job->orderId);
                    self::$instructionJob = $job instanceof App\Jobs\SendKlikQrisCheckoutInstructions;
                    self::$jobKind = self::$instructionJob ? 'instruction' : 'receipt';
                }
            }
        });
        Event::listen(JobProcessed::class, static function (): void {
            if (self::$active !== null) {
                self::observe(self::$active, 'db', ['db_ms' => self::$dbMs, 'job_kind' => self::$jobKind, 'job_token' => self::$jobToken]);
            }
            self::$active = null;
            self::$activeUpdate = null;
            self::$queryActive = false;
        });
        Event::listen(JobFailed::class, static function (): void {
            if (self::$active !== null && self::$instructionJob) {
                self::observe(self::$active, 'instruction_failed');
            }
        });
        Event::listen(JobQueueing::class, static function (JobQueueing $event): void {
            if ($event->job instanceof App\Jobs\ProcessTelegramUpdate) {
                // Source enqueue invocation, before Redis publication; not a
                // claim that the interaction is already available to pop.
                self::observe((string) DogfoodTiming::correlation($event->job->telegramUpdateId), 'interaction_queued');
            }
        });
        Order::created(static function (Order $order): void {
            if (self::$activeUpdate !== null) {
                self::observe(self::$activeUpdate, 'checkout_order', ['order_lookup' => (string) $order->id]);
            }
        });
        Event::listen(JobQueueing::class, static function (JobQueueing $event): void {
            if ($event->job instanceof App\Jobs\SendTelegramReceipt) {
                self::observe((string) DogfoodTiming::correlation($event->job->orderId), 'receipt_queued');
            }
        });
        Log::listen(static function (MessageLogged $event): void {
            $map = [
                'dogfood.telegram.job_picked_up' => 'pickup',
                'dogfood.telegram.handler_finished' => 'handler_finished',
                'dogfood.telegram.payment_observed' => 'paid_observed',
                'dogfood.telegram.paid_committed' => 'paid_committed',
                'dogfood.telegram.receipt_job_picked_up' => 'receipt_pickup',
                'dogfood.telegram.receipt_first_credential_acknowledged' => 'credential_ack',
                'dogfood.telegram.receipt_completed' => 'group_complete',
            ];
            $boundary = $map[$event->message] ?? null;
            if ($boundary === null || ($boundary === 'paid_observed' && ($event->context['provider_status'] ?? '') !== 'SUCCESS')) {
                return;
            }
            $key = $event->context['order_correlation_id'] ?? $event->context['correlation_id'] ?? null;
            if (! is_string($key)) {
                return;
            }
            if (in_array($boundary, ['pickup', 'receipt_pickup'], true)) {
                self::$active = $key;
                if ($boundary === 'pickup') {
                    self::$activeUpdate = $key;
                }
            }
            self::observe($key, $boundary, ['job_token' => self::$jobToken]);
        });
    }

    private static function observe(string $key, string $boundary, array $extra = []): void
    {
        if (preg_match('/\A[a-f0-9]{16}\z/D', $key) !== 1) {
            throw new RuntimeException('probe_error');
        }
        file_put_contents(self::$directory.'/events-'.$key, json_encode(['boundary' => $boundary, 'ns' => hrtime(true)] + $extra, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }

    private static function events(string $key): array
    {
        $path = self::$directory.'/events-'.$key;
        if (! is_file($path)) {
            return [];
        }
        $result = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $result[] = $event;
            }
        }
        return $result;
    }

    private static function seed(string $dataset): array
    {
        if (Product::query()->exists() || Customer::query()->exists() || Order::query()->exists()) {
            throw new RuntimeException('probe_error');
        }
        $shape = $dataset === 'small' ? [4, 12, 20, 3] : [20, 120, 200, 3];
        [$categoryCount, $familyCount, $customerCount, $historyPerCustomer] = $shape;
        ManualPaymentSetting::query()->update(['is_active' => false]);
        ManualPaymentSetting::query()->create(['payment_mode' => 'klikqris', 'is_active' => true, 'admin_telegram_chat_id' => '8999999']);
        foreach (['menu.back' => '⬅️', 'menu.home' => '🏠', 'menu.balance' => '💰', 'message.search' => '🔎', 'message.refresh' => '🔄'] as $key => $glyph) {
            TelegramUiIcon::query()->updateOrCreate(['key' => $key], ['label' => 'P04 '.$key, 'fallback_emoji' => $glyph]);
        }
        Storage::disk('public')->put('p04/probe.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $logical = ['version' => 1, 'identity' => 'p04-repository-synthetic-v1', 'seed' => 686, 'dataset' => $dataset, 'categories' => [], 'families' => [], 'products' => [], 'customers' => [], 'orders' => []];
        $logical['historical_status_cycle'] = ['pending', 'paid', 'expired', 'cancelled', 'late_paid', 'delivery_unknown'];
        $seedOrders = [];
        $categories = [];
        for ($i = 0; $i < $categoryCount; $i++) {
            $categories[] = Category::query()->create(['name' => sprintf('P04 Category %03d', $i), 'slug' => 'p04-category-'.$i, 'is_active' => true, 'sort_order' => $i]);
            $logical['categories'][] = ['logical_id' => 'category:'.$i, 'sort_order' => $i];
        }
        $products = [];
        for ($i = 0; $i < $familyCount; $i++) {
            $family = ProductFamily::query()->create(['name' => sprintf('P04 Family %03d', $i), 'slug' => 'p04-family-'.$i, 'icon' => '📦', 'sort_order' => $i]);
            $logical['families'][] = ['logical_id' => 'family:'.$i, 'category' => $i % $categoryCount, 'icon' => 'package'];
            for ($plan = 0; $plan < 3; $plan++) {
                $n = $i * 3 + $plan;
                $product = Product::query()->create([
                    'category_id' => $categories[$i % $categoryCount]->id,
                    'product_family_id' => $family->id,
                    'family_sort_order' => $plan, 'family_option_label' => 'Plan '.($plan + 1),
                    'name' => sprintf('P04 Product %04d', $n), 'description' => 'Deterministic synthetic migration benchmark plan.',
                    'price_amount' => 10000 + $plan * 1000, 'currency' => 'IDR', 'is_active' => true,
                    'telegram_image_path' => 'p04/probe.png',
                ]);
                // The hot plan supports complete 200-attempt checkout cells;
                // other plans retain a bounded catalog/stock population.
                $stock = $n === 0 ? 1000 : 24;
                $lines = [];
                for ($unit = 0; $unit < $stock; $unit++) {
                    $lines[] = 'p04-'.$n.'-'.$unit.'@example.test|synthetic-p04-'.$n.'-'.$unit;
                }
                $uuid = sprintf('00000679-0686-4000-8000-%012d', $n + 1);
                app(ProductStockIntake::class)->submit($product, ProductStockIntakeSubmission::legacy($lines, $uuid), dispatchNotifications: false);
                $products[] = $product;
                $logical['products'][] = ['logical_id' => 'product:'.$n, 'family' => $i, 'category' => $i % $categoryCount, 'plan' => $plan, 'price_idr' => 10000 + $plan * 1000, 'intake_units' => $stock, 'intake_operation' => $uuid, 'asset' => 'p04/probe.png'];
            }
        }
        for ($i = 0; $i < $customerCount; $i++) {
            $customer = Customer::query()->create(['telegram_id' => 8800000 + $i, 'first_name' => sprintf('P04 Buyer %04d', $i), 'locale' => 'id']);
            $logical['customers'][] = ['logical_id' => 'customer:'.$i, 'locale' => 'id'];
            for ($h = 0; $h < $historyPerCustomer; $h++) {
                $ordinal = $i * $historyPerCustomer + $h;
                $state = $logical['historical_status_cycle'][$ordinal % count($logical['historical_status_cycle'])];
                $product = $products[1 + $ordinal % (count($products) - 1)];
                $order = self::checkout($customer, $product);
                $payment = $order->payments()->sole();
                $seedOrders[$ordinal] = $order;
                if ($state === 'late_paid') {
                    app(OrderLifecycle::class)->applyPaymentExpired($payment);
                    $payment = $payment->fresh();
                }
                if ($state === 'delivery_unknown') {
                    Cache::put('p04:ambiguous-order:'.DogfoodTiming::correlation($order->id), true, 3600);
                }
                if (in_array($state, ['paid', 'late_paid', 'delivery_unknown'], true)) {
                    $provider = app(KlikQrisProviderAdapter::class);
                    $provider->alwaysPending = false;
                    app(KlikQrisCallbackProcessor::class)->processReconciliationForPayment($payment, $provider->transactionStatus($payment));
                    $provider->alwaysPending = true;
                } elseif ($state === 'expired') {
                    app(OrderLifecycle::class)->applyPaymentExpired($payment);
                } elseif ($state === 'cancelled') {
                    app(OrderLifecycle::class)->applyPendingCancellation($order);
                }
                $logical['orders'][] = ['logical_id' => 'order:'.$ordinal, 'customer' => $i, 'product' => 1 + $ordinal % (count($products) - 1), 'requested_state' => $state, 'order_state' => $order->fresh()->order_status->value, 'payment_state' => $payment->fresh()->status->value];
            }
        }
        // Seed effects are delivered, not erased or marked sent by the harness.
        // Resolve receipts before admin notices snapshot their delivery outcome.
        // Otherwise the seed's history depends on which queue wins the race.
        self::startWorkers('normalized', ['telegram-receipts', 'telegram-outbound']);
        self::drain(120, ['telegram-receipts', 'telegram-outbound']);
        self::stopWorkers();
        self::startWorkers('normalized');
        self::drain(120);
        self::stopWorkers();
        foreach ($seedOrders as $ordinal => $seedOrder) {
            $fresh = $seedOrder->fresh();
            $payment = $fresh->payments()->sole();
            $effects = [];
            foreach (App\Models\TelegramOutboundEffect::query()->where('source_type', Order::class)->where('source_id', $fresh->id)->get(['effect_type', 'state']) as $effect) {
                $key = $effect->getRawOriginal('effect_type').'/'.$effect->getRawOriginal('state');
                $effects[$key] = ($effects[$key] ?? 0) + 1;
            }
            ksort($effects);
            $reviews = [];
            foreach (App\Models\ManualFulfillmentReview::query()->where('order_id', $fresh->id)->get(['reason', 'state']) as $review) {
                $key = $review->getRawOriginal('reason').'/'.$review->getRawOriginal('state');
                $reviews[$key] = ($reviews[$key] ?? 0) + 1;
            }
            ksort($reviews);
            $logical['orders'][$ordinal]['order_state'] = $fresh->order_status->value;
            $logical['orders'][$ordinal]['payment_state'] = $payment->status->value;
            $logical['orders'][$ordinal]['outbound_effect_states'] = $effects;
            $logical['orders'][$ordinal]['manual_review_states'] = $reviews;
            Cache::forget('p04:ambiguous-order:'.DogfoodTiming::correlation($fresh->id));
        }
        $counts = self::seedCardinalities();
        $states = [];
        $historyStates = [];
        $effectStates = [];
        foreach ($logical['orders'] as $order) {
            $key = $order['order_state'].'/'.$order['payment_state'];
            $states[$key] = ($states[$key] ?? 0) + 1;
            $historyStates[$order['requested_state']] = ($historyStates[$order['requested_state']] ?? 0) + 1;
            foreach ($order['outbound_effect_states'] as $effectState => $count) {
                $effectStates[$effectState] = ($effectStates[$effectState] ?? 0) + $count;
            }
        }
        ksort($states);
        ksort($historyStates);
        ksort($effectStates);
        $logical['cardinalities'] = $counts;
        $logical['state_distribution'] = $states;
        $logical['historical_state_distribution'] = $historyStates;
        $logical['outbound_effect_state_distribution'] = $effectStates;
        $logical['audit'] = self::seedAudit($customerCount * $historyPerCustomer);
        if ($logical['audit']['queue_backlog'] !== 0 || $logical['audit']['failed_jobs'] !== 0 || $logical['audit']['invalid_stock_projections'] !== 0
            || $counts['orders'] !== $customerCount * $historyPerCustomer || $counts['payments'] !== $counts['orders']
            || $logical['audit']['actual_delivery_unknown_orders'] !== $logical['audit']['expected_delivery_unknown_orders']
            || $logical['audit']['actual_late_paid_reviews'] !== $logical['audit']['expected_late_paid_reviews']) {
            throw new RuntimeException('scenario_error');
        }
        $checksum = hash('sha256', json_encode(self::canonical($logical), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $manifest = $logical + ['logical_sha256' => $checksum, 'logical_checksum_encoding' => 'recursive-object-key-sort; ordered-lists; compact-utf8-json; unescaped-unicode-and-slashes', 'provenance' => ['stock' => ProductStockIntake::class, 'orders' => OrderCheckoutService::class, 'states' => OrderLifecycle::class, 'paid' => KlikQrisCallbackProcessor::class], 'randomness_excluded' => ['physical_uuid', 'ciphertext', 'timestamps', 'provider_reference', 'telegram_message_id']];
        self::persistSeedManifest($dataset, $manifest);
        Cache::put('p04:dataset', ['dataset' => $dataset, 'checksum' => $checksum], 86400);
        return $manifest;
    }

    private static function seedCardinalities(): array
    {
        return ['categories' => Category::count(), 'families' => ProductFamily::count(), 'products' => Product::count(), 'customers' => Customer::count(), 'orders' => Order::count(), 'payments' => Payment::count(), 'stock_items' => DB::table('product_stock_items')->count()];
    }

    private static function seedAudit(int $orders): array
    {
        return [
            'queue_backlog' => self::backlog(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'invalid_stock_projections' => Product::query()->whereColumn('stock', '<', 'reserved_stock')->orWhere('stock', '<', 0)->orWhere('reserved_stock', '<', 0)->count(),
            'order_payment_pairs' => Payment::query()->whereHas('order')->count(),
            'stock_owner_operations' => DB::table('product_stock_intake_operations')->count(),
            'expected_delivery_unknown_orders' => intdiv($orders, 6),
            'actual_delivery_unknown_orders' => App\Models\TelegramOutboundEffect::query()->where('source_type', Order::class)->where('state', 'unknown')->distinct()->count('source_id'),
            'expected_late_paid_reviews' => intdiv($orders, 6),
            'actual_late_paid_reviews' => App\Models\ManualFulfillmentReview::query()->where('reason', App\Models\ManualFulfillmentReview::REASON_LATE_PAYMENT)->count(),
        ];
    }

    private static function seedDatabaseChecksum(): string
    {
        // This private attestation is intentionally physical, unlike the public
        // logical checksum: only an exact pristine PostgreSQL clone is admitted.
        $hash = hash_init('sha256');
        foreach (DB::select("select tablename from pg_catalog.pg_tables where schemaname = 'public' order by tablename") as $table) {
            hash_update($hash, "\0".$table->tablename."\0");
            $identifier = '"'.str_replace('"', '""', $table->tablename).'"';
            foreach (DB::cursor('select row_to_json(seed_row)::text as seed_json from public.'.$identifier.' as seed_row order by row_to_json(seed_row)::text collate "C"') as $row) {
                hash_update($hash, $row->seed_json."\n");
            }
        }
        return hash_final($hash);
    }

    private static function persistSeedManifest(string $dataset, array $manifest): void
    {
        $directory = storage_path('app/private');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('probe_error');
        }
        $path = $directory.'/p04-seed-'.$dataset.'.json';
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('probe_error');
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('probe_error');
            }
            self::writeFlushed($stream, json_encode([
                'version' => 1, 'manifest' => $manifest, 'database_sha256' => self::seedDatabaseChecksum(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        } finally {
            fclose($stream);
        }
        if (! rename($temporary, $path)) {
            throw new RuntimeException('probe_error');
        }
    }

    private static function admitSeed(string $dataset, string $checksum): array
    {
        $path = storage_path('app/private/p04-seed-'.$dataset.'.json');
        if (! is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('probe_error');
        }
        $attestation = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest = $attestation['manifest'] ?? [];
        $logical = $manifest;
        unset($logical['logical_sha256'], $logical['logical_checksum_encoding'], $logical['provenance'], $logical['randomness_excluded']);
        $actualLogicalChecksum = hash('sha256', json_encode(self::canonical($logical), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $expected = $dataset === 'small'
            ? ['categories' => 4, 'families' => 12, 'products' => 36, 'customers' => 20, 'orders' => 60, 'payments' => 60, 'stock_items' => 1840]
            : ['categories' => 20, 'families' => 120, 'products' => 360, 'customers' => 200, 'orders' => 600, 'payments' => 600, 'stock_items' => 9616];
        if (($attestation['version'] ?? null) !== 1 || ($manifest['version'] ?? null) !== 1
            || ($manifest['identity'] ?? null) !== 'p04-repository-synthetic-v1'
            || ($manifest['seed'] ?? null) !== 686 || ($manifest['dataset'] ?? null) !== $dataset
            || ($manifest['logical_sha256'] ?? null) !== $checksum || ! hash_equals($checksum, $actualLogicalChecksum)
            || ($manifest['cardinalities'] ?? null) !== $expected || self::seedCardinalities() !== $expected
            || ($manifest['audit'] ?? null) !== self::seedAudit($expected['orders'])
            || TelegramUpdate::query()->exists()) {
            throw new RuntimeException('scenario_error');
        }
        $states = [];
        foreach (DB::table('orders')->join('payments', 'payments.order_id', '=', 'orders.id')
            ->selectRaw('orders.order_status as order_state, payments.status as payment_state, count(*) as n')
            ->groupBy('orders.order_status', 'payments.status')->get() as $state) {
            $states[$state->order_state.'/'.$state->payment_state] = (int) $state->n;
        }
        ksort($states);
        if (($manifest['state_distribution'] ?? null) !== $states
            || ! is_string($attestation['database_sha256'] ?? null)
            || ! hash_equals($attestation['database_sha256'], self::seedDatabaseChecksum())) {
            throw new RuntimeException('scenario_error');
        }
        Cache::put('p04:dataset', ['dataset' => $dataset, 'checksum' => $checksum], 86400);
        return $manifest;
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        return $value;
    }

    private static function checkout(Customer $customer, Product $product): Order
    {
        $carts = app(CartService::class);
        $cart = $carts->activeCartFor($customer);
        foreach ($cart->cartItems()->get() as $item) {
            $carts->removeItem($cart, Product::query()->findOrFail($item->product_id));
        }
        $carts->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::klikQris());
        $payment = $order->payments()->sole();
        if (! app(KlikQrisNativeQrisService::class)->lifecycle($payment)->isPayable()) {
            throw new RuntimeException('provider_error');
        }
        return $order;
    }

    private static function startWorkers(string $profile, ?array $queues = null): void
    {
        $sleeps = ['default' => 0, 'payments' => 1, 'telegram-receipts' => 1, 'notifications' => 3, 'telegram-outbound' => 1, 'telegram-broadcast' => 1, 'telegram-stock' => 1, 'suppliers' => 1, 'binance-verification' => 3];
        $queues ??= array_keys($sleeps);
        foreach ($queues as $queue) {
            if (! array_key_exists($queue, $sleeps) || isset(self::$processes[$queue])) {
                throw new RuntimeException('probe_error');
            }
            @unlink(self::$directory.'/worker-stop-'.$queue);
            @unlink(self::$directory.'/worker-ready-'.$queue);
            $file = self::$directory.'/worker-'.$queue.'.json';
            file_put_contents($file, json_encode(['kind' => 'worker', 'queue' => $queue, 'sleep' => $profile === 'current' ? $sleeps[$queue] : 0], JSON_THROW_ON_ERROR));
            self::$processes[$queue] = self::spawn($file);
        }
        $deadline = microtime(true) + 30;
        do {
            $ready = true;
            foreach ($queues as $queue) {
                if (! is_file(self::$directory.'/worker-ready-'.$queue)
                    || trim((string) file_get_contents(self::$directory.'/worker-ready-'.$queue)) !== (string) self::$processes[$queue]) {
                    $ready = false;
                }
            }
            if (glob(self::$directory.'/child-error-*.json')) {
                throw new RuntimeException('probe_error');
            }
            if ($ready) {
                return;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('timeout');
            }
            usleep(10000);
        } while (true);
    }

    private static function spawn(string $file): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('probe_error');
        }
        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [__FILE__, '--internal', $file]);
            exit(1);
        }
        return $pid;
    }

    private static function worker(array $job): void
    {
        // runNextJob is Laravel's real pop/reserve/process/release path. A
        // finite loop permits clean drain/stop without production daemons.
        $worker = app('queue.worker');
        $options = new WorkerOptions(
            name: 'p04-'.$job['queue'],
            backoff: 0,
            timeout: in_array($job['queue'], ['suppliers', 'binance-verification'], true) ? 120 : 60,
            sleep: $job['sleep'],
            maxTries: $job['queue'] === 'suppliers' ? 1 : 3,
            force: true,
        );
        file_put_contents(self::$directory.'/worker-ready-'.$job['queue'], (string) getmypid());
        while (! is_file(self::$directory.'/worker-stop-'.$job['queue'])) {
            $worker->runNextJob($job['queue'] === 'suppliers' ? 'supplier' : 'redis', $job['queue'], $options);
        }
    }

    private static function stopWorkers(?array $queues = null): void
    {
        $queues ??= array_keys(self::$processes);
        foreach ($queues as $queue) {
            if (isset(self::$processes[$queue])) {
                file_put_contents(self::$directory.'/worker-stop-'.$queue, '1');
            }
        }
        foreach ($queues as $queue) {
            if (! isset(self::$processes[$queue])) {
                continue;
            }
            $pid = self::$processes[$queue];
            $deadline = microtime(true) + 8;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) > $deadline) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                    break;
                }
                usleep(10000);
            }
            unset(self::$processes[$queue]);
            @unlink(self::$directory.'/worker-stop-'.$queue);
            @unlink(self::$directory.'/worker-ready-'.$queue);
        }
    }

    private static function backlog(?array $queues = null): int
    {
        $count = 0;
        foreach ($queues ?? QueueTopology::operational() as $queue) {
            $count += Queue::connection('redis')->size($queue);
        }
        return $count;
    }

    private static function drain(int $seconds, ?array $queues = null): void
    {
        $deadline = microtime(true) + $seconds;
        $empty = 0;
        while ($empty < 3) {
            $empty = self::backlog($queues) === 0 ? $empty + 1 : 0;
            if (microtime(true) > $deadline) {
                throw new RuntimeException('timeout');
            }
            usleep(100000);
        }
    }

    private static function startClients(int $count): void
    {
        for ($lane = 0; $lane < $count; $lane++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                throw new RuntimeException('probe_error');
            }
            DB::disconnect();
            Redis::purge('default');
            Redis::purge('cache');
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('probe_error');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach (self::$clients as $client) {
                    fclose($client['socket']);
                }
                self::$clients = [];
                self::$processes = [];
                while (($line = fgets($pair[1])) !== false) {
                    $job = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if ($job === null) {
                        break;
                    }
                    self::attempt($job);
                    fwrite($pair[1], "complete\n");
                }
                fclose($pair[1]);
                exit(0);
            }
            fclose($pair[1]);
            self::$clients[] = ['pid' => $pid, 'socket' => $pair[0]];
        }
    }

    private static function stopClients(): void
    {
        foreach (self::$clients as $client) {
            @fwrite($client['socket'], "null\n");
            fclose($client['socket']);
            pcntl_waitpid($client['pid'], $status);
        }
        self::$clients = [];
    }

    private static function receive(Customer $customer, ?string $callback, bool $timed = false): array
    {
        $chat = (int) $customer->telegram_id;
        $id = 1000000000 + Cache::increment('p04:update-sequence');
        $from = ['id' => $chat, 'is_bot' => false, 'first_name' => 'P04 Buyer'];
        $payload = ['update_id' => $id];
        if ($callback === null) {
            $payload['message'] = ['message_id' => $id, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => $from, 'text' => '/start'];
        } else {
            $root = TelegramNavigationRoot::query()->where('customer_id', $customer->id)->firstOrFail();
            $payload['callback_query'] = ['id' => 'p04-'.$id, 'from' => $from, 'message' => ['message_id' => $root->message_id, 'chat' => ['id' => $chat, 'type' => 'private']], 'data' => $callback];
        }
        $request = Request::create('/api/webhook/telegram', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'p04-synthetic-webhook-secret'], json_encode($payload, JSON_THROW_ON_ERROR));
        $kernel = app(HttpKernel::class);
        self::$dbMs = 0;
        self::$queryActive = $timed;
        $ingress = hrtime(true);
        $response = $kernel->handle($request);
        $ack = hrtime(true);
        $kernel->terminate($request, $response);
        self::$queryActive = false;
        $dbMs = self::$dbMs;
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('unexpected_response');
        }
        $update = TelegramUpdate::query()->where('update_id', $id)->firstOrFail();
        return ['update' => $update, 'key' => DogfoodTiming::correlation($update->id), 'ingress' => $ingress, 'ingress_ack' => $ack, 'db_ms' => $dbMs];
    }

    private static function awaitUpdate(array $received): void
    {
        $deadline = microtime(true) + 45;
        do {
            $update = $received['update']->fresh();
            if ($update->terminal_failed_at !== null) {
                throw new RuntimeException('scenario_error');
            }
            if ($update->processed_at !== null && array_any(self::events($received['key']), static fn (array $event): bool => $event['boundary'] === 'db')) {
                return;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('timeout');
    }

    private static function controls(Customer $customer, string $prefix): string
    {
        foreach (Cache::get('p04:controls:'.DogfoodTiming::correlation($customer->telegram_id), []) as $callback) {
            if (str_starts_with($callback, $prefix)) {
                return $callback;
            }
        }
        throw new RuntimeException('scenario_error');
    }

    private static function setup(Customer $customer, string $scenario): string
    {
        self::awaitUpdate(self::receive($customer, null));
        if (in_array($scenario, ['home_catalog', 'orders', 'balance'], true)) {
            return match ($scenario) {'home_catalog' => 'katalog', 'orders' => 'menu:orders', 'balance' => 'balance'};
        }
        self::awaitUpdate(self::receive($customer, 'katalog'));
        $options = self::controls($customer, 'family:options:');
        if ($scenario === 'catalog_plans') {
            return $options;
        }
        if ($scenario === 'home') {
            return 'family:back:menu';
        }
        self::awaitUpdate(self::receive($customer, $options));
        $select = self::controls($customer, 'family:select:');
        if ($scenario === 'plan_detail') {
            return $select;
        }
        self::awaitUpdate(self::receive($customer, $select));
        return match ($scenario) {
            'back' => self::controls($customer, 'detail:back:'),
            'quantity' => self::controls($customer, 'detail:qty:+:'),
            'checkout', 'paid_delivery' => self::controls($customer, 'detail:pay:qris:'),
            default => throw new RuntimeException('scenario_error'),
        };
    }

    private static function pace(Customer $customer, int $requests): void
    {
        $request = Request::create('/api/webhook/telegram', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'p04-synthetic-webhook-secret',
        ], json_encode(['callback_query' => ['from' => ['id' => (int) $customer->telegram_id]]], JSON_THROW_ON_ERROR));
        $limiter = app(Illuminate\Cache\RateLimiter::class);
        // Capture the real named middleware's normalized keys without hitting
        // a counter, executing ingress, or maintaining a second key convention.
        $middleware = new class($limiter) extends Illuminate\Routing\Middleware\ThrottleRequests
        {
            public array $observedLimits = [];

            protected function handleRequest($request, Closure $next, array $limits)
            {
                $this->observedLimits = $limits;
                return new Symfony\Component\HttpFoundation\Response;
            }
        };
        $middleware->handle($request, static fn () => new Symfony\Component\HttpFoundation\Response, 'telegram');
        foreach ($middleware->observedLimits as $limit) {
            if ($requests > $limit->maxAttempts) {
                throw new RuntimeException('scenario_error');
            }
            while ($limiter->remaining($limit->key, $limit->maxAttempts) < $requests) {
                $started = hrtime(true);
                sleep(max(1, $limiter->availableIn($limit->key)));
                self::$pacingMs += (hrtime(true) - $started) / 1000000;
                self::$pacingWaits++;
            }
        }
    }

    private static function batch(array $input): array
    {
        $marker = Cache::get('p04:dataset');
        if (($marker['dataset'] ?? null) !== $input['dataset']) {
            throw new RuntimeException('probe_error');
        }
        $customers = Customer::query()->orderBy('telegram_id')->limit(10)->get();
        if ($customers->count() !== 10) {
            throw new RuntimeException('probe_error');
        }
        $samples = [];
        $before = self::population();
        $backgroundCount = 0;
        $phaseStarted = hrtime(true);
        $phaseMs = [];
        $markPhase = static function (string $phase) use (&$phaseStarted, &$phaseMs): void {
            $now = hrtime(true);
            $phaseMs[$phase] = ($phaseMs[$phase] ?? 0) + ($now - $phaseStarted) / 1000000;
            $phaseStarted = $now;
        };
        self::startWorkers($input['profile']);
        $markPhase('start_workers');
        for ($first = 0; $first < $input['samples']; $first += $input['users']) {
            $width = min($input['users'], $input['samples'] - $first);
            $callbacks = [];
            $setupError = null;
            try {
                $setupRequests = match ($input['scenario']) {
                    'home_catalog', 'orders', 'balance' => 1,
                    'catalog_plans', 'home' => 2,
                    'plan_detail' => 3,
                    default => 4,
                };
                for ($lane = 0; $lane < ($input['traffic'] === 'same_chat' ? 1 : $width); $lane++) {
                    self::pace($customers[$lane], $setupRequests + ($input['traffic'] === 'same_chat' ? $width : 1));
                }
                if (in_array($input['scenario'], ['checkout', 'paid_delivery'], true)) {
                    self::ensureStock(Product::query()->orderBy('id')->firstOrFail(), $width + 2);
                }
                for ($lane = 0; $lane < ($input['traffic'] === 'same_chat' ? 1 : $width); $lane++) {
                    $callbacks[$lane] = self::setup($customers[$lane], $input['scenario']);
                }
            } catch (Throwable $exception) {
                $setupError = self::errorCode($exception);
            }
            $markPhase('context_setup_including_pacing');
            try {
                self::drain(90);
            } catch (Throwable $exception) {
                $setupError = self::errorCode($exception);
            }
            $markPhase('drain_before');
            if ($input['temperature'] === 'cold') {
                $coldQueues = match ($input['scenario']) {
                    'checkout' => ['default', 'telegram-outbound'],
                    'paid_delivery' => ['default', 'telegram-outbound', 'payments', 'telegram-receipts'],
                    default => ['default'],
                };
                self::stopWorkers($coldQueues);
                // Cache clear is deliberately outside the measured operation;
                // setup controls are already copied to the request envelope.
                // Do not flush session/intent caches: that would invalidate the
                // canonical context and benchmark a stale callback instead.
                Cache::forget(App\Services\Telegram\TelegramUiIcons::CACHE_KEY);
                app(App\Services\Telegram\TelegramCatalogProjectionCache::class)->invalidate();
                self::startWorkers($input['profile'], $coldQueues);
            }
            $markPhase('cold_path_restart');
            if ($input['background'] === 'representative') {
                try {
                    $backgroundCount += self::background();
                } catch (Throwable $exception) {
                    $setupError = self::errorCode($exception);
                }
            }
            $markPhase('background_submit');
            $pids = [];
            $wave = intdiv($first, $input['users']);
            if ($input['temperature'] === 'warm' && self::$clients === []) {
                self::startClients($input['users']);
            }
            for ($lane = 0; $lane < $width; $lane++) {
                $customer = $customers[$input['traffic'] === 'same_chat' ? 0 : $lane];
                $job = $input + ['kind' => 'attempt', 'ordinal' => $first + $lane + 1, 'wave' => $wave, 'lane' => $lane, 'customer_lookup' => (int) $customer->telegram_id, 'callback' => $callbacks[$input['traffic'] === 'same_chat' ? 0 : $lane] ?? null, 'setup_error' => $setupError];
                if ($input['temperature'] === 'cold') {
                    $path = self::$directory.'/attempt-'.($first + $lane + 1).'.json';
                    file_put_contents($path, json_encode($job, JSON_THROW_ON_ERROR));
                    $pids[] = self::spawn($path);
                } else {
                    fwrite(self::$clients[$lane]['socket'], json_encode($job, JSON_THROW_ON_ERROR)."\n");
                }
            }
            $deadline = microtime(true) + 35;
            while (count(glob(self::$directory.'/ready-'.$wave.'-*') ?: []) < $width) {
                if (microtime(true) > $deadline) {
                    break;
                }
                usleep(1000);
            }
            file_put_contents(self::$directory.'/go-'.$wave, '1');
            $markPhase('client_ready');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            if ($input['temperature'] === 'warm') {
                for ($lane = 0; $lane < $width; $lane++) {
                    fgets(self::$clients[$lane]['socket']);
                }
            }
            for ($lane = 0; $lane < $width; $lane++) {
                $ordinal = $first + $lane + 1;
                $file = self::$directory.'/result-'.$ordinal.'.json';
                if (is_file($file)) {
                    $sample = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                } else {
                    $sample = self::recoverRecord($input['evidence_path'], $ordinal);
                    if ($sample === null) {
                        $sample = self::record($input, $ordinal, 'error', 'probe_error');
                        self::persistRecord($input['evidence_path'], $sample);
                    }
                }
                $samples[] = $sample;
            }
            $markPhase('request_wave_and_collection');
            try {
                self::drain(90);
            } catch (Throwable) {
                // Keep every completed or failed attempt even when delayed
                // retries leave a nonempty backlog; accounting exposes it.
            }
            $markPhase('drain_after');
        }
        $backlog = self::backlog();
        self::stopClients();
        self::stopWorkers();
        $markPhase('shutdown');
        return ['samples' => $samples, 'accounting' => [
            'harness_phase_ms' => $phaseMs,
            'attempts' => count($samples), 'successes' => count(array_filter($samples, static fn (array $row): bool => $row['outcome'] === 'success')),
            'queue_backlog' => $backlog, 'failed_jobs' => DB::table('failed_jobs')->count(),
            'db_connections' => (int) DB::selectOne('select count(*) as n from pg_stat_activity where datname = current_database()')->n,
            'background_jobs_submitted' => $backgroundCount, 'worker_processes' => 9,
            'worker_policy' => [
                'connection' => ['suppliers' => 'supplier', 'other_queues' => 'redis'],
                'timeout_seconds' => ['suppliers' => 120, 'binance-verification' => 120, 'other_queues' => 60],
                'tries' => ['suppliers' => 1, 'other_queues' => 3],
                'daemon_recycling' => 'bounded-probe-loop-not-supervisor-daemon',
            ],
            'background_waves' => $input['background'] === 'representative' ? intdiv($input['samples'] + $input['users'] - 1, $input['users']) : 0,
            'background_jobs_expected' => $input['background'] === 'representative' ? 2 * intdiv($input['samples'] + $input['users'] - 1, $input['users']) : 0,
            'request_model' => 'in-process-http-kernel-in-concurrent-cli-processes-not-fpm',
            'warm_model' => 'persistent-independent-http-client-processes-and-persistent-queue-workers; prebooted-kernel-inherited-after-context-setup',
            'cold_model' => 'v2-fresh-exec-request-clients-per-wave; fresh-exec-path-workers-nav=default,checkout=default+telegram-outbound,paid_delivery=default+telegram-outbound+payments+telegram-receipts; unrelated-workers-persist; fresh-worker-ready-before-timed-action; icons-and-catalog-projection-caches-cleared-outside-timing; canonical-intent-caches-preserved; no-os-cold-claim',
            'profile_model' => $input['profile'] === 'current' ? 'source-nine-queues-source-sleep-values' : 'same-nine-queues-zero-worker-sleep',
            'dataset_logical_sha256' => $marker['checksum'],
            'requires_fresh_migration_before_independent_cell' => false,
            'population_before' => $before,
            'population_after' => self::population(),
            'state_model' => 'v2-pristine-seeded-postgresql-clone-before-independent-cell-batch; warmup-and-measurement-share-clone; local-redis-reset-and-attested-seed-admission-before-cell; no-cross-cell-history',
            'admission_model' => 'source-webhook-limit-preserved; untimed-canonical-context-setup-consumes-same-admission-buckets',
            'pacing_ms' => round(self::$pacingMs, 3),
            'pacing_waits' => self::$pacingWaits,
            'arrival_policy' => 'outside-timing-source-limiter-remaining-budget-for-canonical-setup-and-complete-concurrent-wave; wait-real-expiry; no-429-retry',
            'background_model' => 'bounded-real-provider-verification-and-new-expired-order-notification-per-wave; no-sustained-rate-claim',
        ]];
    }

    private static function background(): int
    {
        $customer = Customer::query()->orderByDesc('telegram_id')->firstOrFail();
        $product = Product::query()->orderBy('id')->skip(1)->firstOrFail();
        self::ensureStock($product, 2);
        $verification = self::checkout($customer, $product);
        $payment = $verification->payments()->sole();
        self::ensureStock($product, 2);
        $order = self::checkout($customer, $product);
        app(OrderLifecycle::class)->applyPaymentExpired($order->payments()->sole());
        VerifyProviderPaymentStatus::dispatch((string) $payment->id, (string) $customer->id, 'klikqris', now()->toISOString());
        SendAdminOrderExpiredNotification::dispatch((string) $order->id, now()->toISOString())->onQueue(QueueTopology::NOTIFICATIONS);
        return 2;
    }

    private static function population(): array
    {
        return ['orders' => Order::count(), 'payments' => Payment::count(), 'stock_items' => DB::table('product_stock_items')->count(), 'updates' => TelegramUpdate::count(), 'outbound_effects' => DB::table('telegram_outbound_effects')->count()];
    }

    private static function ensureStock(Product $product, int $minimum): void
    {
        if ($product->fresh()->availableStock() >= $minimum) {
            return;
        }
        $sequence = DB::table('product_stock_intake_operations')->count();
        $items = [];
        for ($unit = 0; $unit < 100; $unit++) {
            $items[] = 'p04-refill-'.$sequence.'-'.$unit.'@example.test|synthetic-refill-'.$sequence.'-'.$unit;
        }
        $uuid = sprintf('00000679-0686-4001-8000-%012d', $sequence + 1);
        app(ProductStockIntake::class)->submit($product, ProductStockIntakeSubmission::legacy($items, $uuid), dispatchNotifications: false);
    }

    private static function attempt(array $job): void
    {
        $record = self::record($job, $job['ordinal'], 'unknown', 'boundary_unknown');
        $coalesced = false;
        try {
            $customer = Customer::query()->where('telegram_id', $job['customer_lookup'])->firstOrFail();
            file_put_contents(self::$directory.'/ready-'.$job['wave'].'-'.$job['lane'], '1');
            $deadline = microtime(true) + 40;
            while (! is_file(self::$directory.'/go-'.$job['wave'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('timeout');
                }
                usleep(1000);
            }
            if ($job['setup_error'] !== null) {
                throw new RuntimeException($job['setup_error']);
            }
            $received = self::receive($customer, $job['callback'], true);
            $record['timestamps']['ingress'] = $received['ingress'];
            $record['timestamps']['ingress_ack'] = $received['ingress_ack'];
            $record['db_ms'] = $received['db_ms'];
            self::awaitUpdate($received);
            $events = self::events($received['key']);
            foreach ($events as $event) {
                if (in_array($event['boundary'], ['interaction_queued', 'pickup', 'screen_ack'], true) && $record['timestamps'][$event['boundary']] === null) {
                    $record['timestamps'][$event['boundary']] = $event['ns'];
                }
                if (isset($event['db_ms'])) {
                    $record['db_ms'] += $event['db_ms'];
                }
            }
            $record['retries'] = max(0, count(array_filter($events, static fn (array $event): bool => $event['boundary'] === 'pickup')) - 1);
            if (in_array($job['scenario'], ['checkout', 'paid_delivery'], true)) {
                // The handler's real Order::created event elects the originating
                // update. Concurrent idempotent callbacks never borrow its ACK.
                $ownership = array_values(array_filter($events, static fn (array $event): bool => $event['boundary'] === 'checkout_order'));
                if ($ownership === []) {
                    if ($job['traffic'] !== 'same_chat') {
                        throw new RuntimeException('scenario_error');
                    }
                    $coalesced = true;
                } else {
                    if (count($ownership) !== 1) {
                        throw new RuntimeException('scenario_error');
                    }
                    $order = Order::query()->where('customer_id', $customer->id)->findOrFail($ownership[0]['order_lookup']);
                    $payment = $order->payments()->sole();
                    $orderKey = (string) DogfoodTiming::correlation($order->id);
                    $deadline = microtime(true) + 45;
                    $instructionToken = null;
                    $instructionAccounted = false;
                    do {
                        $instructionEvents = self::events($orderKey);
                        foreach ($instructionEvents as $event) {
                            if (in_array($event['boundary'], ['checkout_ready', 'screen_ack'], true) && $record['timestamps'][$event['boundary']] === null) {
                                $record['timestamps'][$event['boundary']] = $event['ns'];
                                if ($event['boundary'] === 'checkout_ready') {
                                    $instructionToken = $event['job_token'] ?? null;
                                }
                            }
                        }
                        $instructionAccounted = self::accounted($instructionEvents, 'instruction', $instructionToken);
                        if ($record['timestamps']['checkout_ready'] !== null && $instructionAccounted) {
                            break;
                        }
                        if (array_any($instructionEvents, static fn (array $event): bool => $event['boundary'] === 'instruction_failed')) {
                            throw new RuntimeException('provider_error');
                        }
                        usleep(5000);
                    } while (microtime(true) < $deadline);
                    if ($record['timestamps']['checkout_ready'] === null || ! $instructionAccounted) {
                        throw new RuntimeException('timeout');
                    }
                    if (! app(KlikQrisNativeQrisService::class)->classify($payment->fresh())->isPayable()) {
                        throw new RuntimeException('provider_error');
                    }
                    if ($job['scenario'] === 'paid_delivery') {
                        $key = (string) DogfoodTiming::correlation($order->id);
                        // The fixture's state changes here; provider wall time is
                        // not converted to an invented monotonic timestamp.
                        Cache::put('p04:paid:'.DogfoodTiming::correlation($payment->id), true, 3600);
                        $record['timestamps']['provider_paid'] = hrtime(true);
                        self::$dbMs = 0;
                        self::$queryActive = true;
                        // The existing status-verification job owns provider read
                        // and paid commit; the synthetic observation is cross-process.
                        VerifyProviderPaymentStatus::dispatch((string) $payment->id, (string) $customer->id, 'klikqris', now()->toISOString());
                        self::$queryActive = false;
                        $record['db_ms'] += self::$dbMs;
                        $deadline = microtime(true) + 60;
                        $receiptToken = null;
                        $receiptAccounted = false;
                        $verificationToken = null;
                        $verificationAccounted = false;
                        do {
                            $paidEvents = self::events($key);
                            foreach ($paidEvents as $event) {
                                if (in_array($event['boundary'], self::BOUNDARIES, true) && $record['timestamps'][$event['boundary']] === null) {
                                    $record['timestamps'][$event['boundary']] = $event['ns'];
                                    if ($event['boundary'] === 'group_complete') {
                                        $receiptToken = $event['job_token'] ?? null;
                                    }
                                    if (in_array($event['boundary'], ['paid_observed', 'paid_committed'], true)) {
                                        $verificationToken = $event['job_token'] ?? null;
                                    }
                                }
                            }
                            $receiptAccounted = self::accounted($paidEvents, 'receipt', $receiptToken);
                            $verificationAccounted = self::accounted($paidEvents, 'verification', $verificationToken);
                            if ($record['timestamps']['group_complete'] !== null && $receiptAccounted && $verificationAccounted) {
                                break;
                            }
                            usleep(10000);
                        } while (microtime(true) < $deadline);
                        if ($record['timestamps']['group_complete'] === null || ! $receiptAccounted || ! $verificationAccounted) {
                            throw new RuntimeException('timeout');
                        }
                    }
                    foreach (self::events($orderKey) as $event) {
                        if (isset($event['db_ms'])) {
                            $record['db_ms'] += $event['db_ms'];
                        }
                    }
                }
            }
            if (! $coalesced && $record['timestamps']['screen_ack'] !== null) {
                $record['outcome'] = 'success';
                $record['error_code'] = null;
            }
        } catch (Throwable $exception) {
            $record['error_code'] = self::errorCode($exception);
            $record['outcome'] = $record['error_code'] === 'timeout' ? 'timeout' : 'error';
        }
        self::persistRecord($job['evidence_path'], $record);
        file_put_contents(self::$directory.'/result-'.$job['ordinal'].'.json', json_encode($record, JSON_THROW_ON_ERROR));
    }

    private static function record(array $input, int $ordinal, string $outcome, ?string $error): array
    {
        return ['schema_version' => 1, 'implementation' => 'laravel', 'profile' => $input['profile'], 'transport' => 'synthetic', 'dataset' => $input['dataset'], 'scenario' => $input['scenario'], 'users' => $input['users'], 'traffic' => $input['traffic'], 'temperature' => $input['temperature'], 'background' => $input['background'], 'batch' => $input['batch'], 'attempt' => $ordinal, 'warmup' => $input['warmup'], 'outcome' => $outcome, 'retries' => 0, 'timestamps' => array_fill_keys(self::BOUNDARIES, null), 'db_ms' => null, 'error_code' => $error];
    }

    private static function errorCode(Throwable $exception): string
    {
        return in_array($exception->getMessage(), ['timeout', 'probe_error', 'provider_error', 'transport_error', 'scenario_error', 'boundary_unknown', 'unexpected_response'], true) ? $exception->getMessage() : 'probe_error';
    }

    private static function removeRuntime(): void
    {
        if (! isset(self::$directory) || ! is_dir(self::$directory)) {
            return;
        }
        foreach (glob(self::$directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir(self::$directory);
    }
}

MigrationProbe::main();
