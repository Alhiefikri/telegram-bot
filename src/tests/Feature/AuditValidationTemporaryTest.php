<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendManualCheckoutNotifications;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerLoginLink;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\TelegramOutboundEffect;
use App\Services\Admin\AdminLoginThrottle;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\TelegramBotTestSupport;

/**
 * Temporary red-capable reproductions for the 2026-08-17 read-only audit.
 *
 * These tests intentionally encode the desired safe behavior. They are kept
 * uncommitted as draft evidence until the remediation tickets are implemented.
 */
class AuditValidationTemporaryTest extends TelegramBotTestSupport
{
    public function test_ambiguous_telegram_receipt_failure_keeps_the_durable_claim(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $telegramAccepted = false;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function () use (&$telegramAccepted): never {
                $telegramAccepted = true;
                throw new ConnectionException('timeout after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        try {
            app(TelegramReceiptNotifier::class)->send($order);
            $this->fail('The simulated ambiguous Telegram failure should be surfaced to the queue worker.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Telegram Bot API sendMessage request failed', $exception->getMessage());
        }

        $this->assertTrue($telegramAccepted);
        $this->assertSame(
            TelegramOutboundEffectState::Unknown,
            TelegramOutboundEffect::query()->sole()->state,
            'An ambiguous send outcome must remain in the ledger instead of being retried automatically.',
        );
    }

    public function test_public_telegram_checkout_keeps_claim_after_ambiguous_customer_send(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'audit-buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Audit Product', 'stock' => 1, 'reserved_stock' => 0]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $telegramAccepted = false;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$telegramAccepted): never {
                $telegramAccepted = true;
                throw new ConnectionException('timeout after Telegram accepted the checkout photo');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123456]]),
        ]);

        try {
            $this->postTelegramWebhook($this->callbackUpdate(9001, 'audit-checkout-timeout', 'checkout'));
        } catch (\Throwable) {
            // The synchronous testing queue surfaces the failed outbound job.
        }

        $order = Order::query()->firstOrFail();
        $this->assertTrue($telegramAccepted);
        $this->assertSame(
            TelegramOutboundEffectState::Unknown,
            TelegramOutboundEffect::query()
                ->where('effect_type', TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT)
                ->sole()
                ->state,
        );
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_public_checkout_reaches_a_real_local_telegram_http_boundary_before_ambiguous_failure(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the local HTTP boundary test.');
        }

        $server = $this->startLocalTelegramBoundaryServer();

        try {
            config()->set('services.telegram.api_base_url', $server['base_url']);
            config()->set('services.telegram.http_timeout_seconds', 1);
            config()->set('services.telegram.http_connect_timeout_seconds', 1);
            Http::allowStrayRequests([$server['base_url'].'/*']);
            ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
            $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'boundary-buyer', 'locale' => 'id']);
            $product = Product::factory()->create(['name' => 'Boundary Product', 'stock' => 1, 'reserved_stock' => 0]);
            app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

            try {
                $this->postTelegramWebhook($this->callbackUpdate(9002, 'audit-boundary-timeout', 'checkout'));
            } catch (\Throwable) {
                // The synchronous testing queue surfaces the failed outbound job.
            }

            $this->waitForAuditFile($server['accepted_marker']);
            $this->assertStringContainsString('/sendPhoto', file_get_contents($server['request_log']) ?: '');

            $order = Order::query()->firstOrFail();
            $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
        } finally {
            Http::preventStrayRequests();
            $this->stopLocalTelegramBoundaryServer($server['process']);
        }
    }

    public function test_recovery_rediscovers_a_committed_checkout_with_no_notification_claim(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => CartStatus::Active,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        // This service call commits the Order, Payment, reservation, and cart
        // state. The process is then treated as crashed before the callback's
        // post-commit dispatchCheckoutInstructions() call.
        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::manualQris(),
        );

        Artisan::call('telegram:recover-outbound', [
            '--chunk' => 100,
            '--queue' => 'telegram-outbound',
        ]);

        Queue::assertPushed(SendManualCheckoutNotifications::class);
    }

    public function test_recovery_does_not_retry_a_sales_proof_post_without_authoritative_evidence(): void
    {
        Queue::fake();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        SalesProofPost::create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_SENDING,
            'attempts' => 1,
        ]);

        Artisan::call('telegram:recover-outbound', [
            '--chunk' => 100,
            '--queue' => 'notifications',
        ]);

        Queue::assertNotPushed(PublishSalesProofPost::class);
    }

    public function test_customer_login_link_does_not_mass_assign_its_used_state(): void
    {
        $link = CustomerLoginLink::create([
            'email' => 'audit@example.test',
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'expires_at' => now()->addMinutes(15),
            'used_at' => now(),
        ]);

        $this->assertNull(
            $link->fresh()->used_at,
            'The one-time-used state must not be writable through an untrusted mass-assignment payload.',
        );
    }

    public function test_admin_login_throttle_blocks_an_eleventh_bad_attempt_from_the_same_ip_across_logins(): void
    {
        $ip = '198.51.100.77';

        foreach (range(1, 10) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post(route('admin.login.store'), [
                    'email' => "audit-login-{$attempt}@example.test",
                    'password' => 'wrong-password',
                ])
                ->assertSessionHasErrors(['email' => 'Email atau password admin tidak cocok.']);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => 'audit-login-eleventh@example.test',
                'password' => 'wrong-password',
            ]);

        $errors = $response->getSession()->get('errors');
        $messages = data_get($errors, 'default.messages.email', []);
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Terlalu banyak percobaan login', $messages[0]);

        $throttle = app(AdminLoginThrottle::class);
        foreach (range(1, 10) as $attempt) {
            $throttle->clearIdentity("audit-login-{$attempt}@example.test", $ip);
        }
        RateLimiter::clear($throttle->ipKey($ip));
    }

    /** @return array{process: resource, base_url: string, request_log: string, accepted_marker: string} */
    private function startLocalTelegramBoundaryServer(): array
    {
        $directory = rtrim((string) (getenv('JCODE_SCRATCH_DIR') ?: sys_get_temp_dir()), '/').'/audit-telegram-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $router = $directory.'/server.php';
        $ready = $directory.'/ready';
        $requestLog = $directory.'/requests.log';
        $acceptedMarker = $directory.'/accepted';
        $portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($portProbe, $errorMessage);
        $address = stream_socket_get_name($portProbe, false);
        fclose($portProbe);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        file_put_contents($router, <<<'PHP'
<?php

$port = (int) ($argv[1] ?? 0);
$ready = (string) ($argv[2] ?? '');
$requestLog = (string) ($argv[3] ?? '');
$acceptedMarker = (string) ($argv[4] ?? '');
$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);
if (! is_resource($server)) {
    exit(1);
}
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 30;
while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, 1);
    if (! is_resource($connection)) {
        continue;
    }
    stream_set_timeout($connection, 2);
    $request = '';
    while (! str_contains($request, "\r\n\r\n") && strlen($request) < 2_000_000) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    $requestLine = strtok($request, "\r\n") ?: '';
    $parts = explode(' ', $requestLine, 3);
    $path = $parts[1] ?? '';
    file_put_contents($requestLog, $path.PHP_EOL, FILE_APPEND);
    if (str_ends_with((string) parse_url($path, PHP_URL_PATH), '/sendPhoto') && ! file_exists($acceptedMarker)) {
        file_put_contents($acceptedMarker, 'accepted');
        fclose($connection);
        continue;
    }
    $body = json_encode(['ok' => true, 'result' => ['message_id' => 654321]]);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    fclose($connection);
}
fclose($server);
PHP);

        $process = proc_open(
            [PHP_BINARY, $router, (string) $port, $ready, $requestLog, $acceptedMarker],
            [0 => ['pipe', 'r'], 1 => ['file', $directory.'/stdout.log', 'a'], 2 => ['file', $directory.'/stderr.log', 'a']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->waitForAuditFile($ready);

        return [
            'process' => $process,
            'base_url' => 'http://127.0.0.1:'.$port,
            'request_log' => $requestLog,
            'accepted_marker' => $acceptedMarker,
        ];
    }

    private function waitForAuditFile(string $path): void
    {
        $deadline = microtime(true) + 5;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($path);
    }

    private function stopLocalTelegramBoundaryServer(mixed $process): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
