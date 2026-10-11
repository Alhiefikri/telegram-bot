<?php

namespace Tests\Feature;

use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\OrderCredentialMailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class OrderCredentialMailPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    private ?string $orderId = null;

    private ?int $customerId = null;

    private ?int $productId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The queue is a wakeup path only: this suite exercises the durable row
        // transitions, so a synchronous local dispatch must never send here.
        Queue::fake();

        if (! $this->concurrencyLaneAvailable()) {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('order_notification_events')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
    }

    protected function tearDown(): void
    {
        if ($this->concurrencyLaneAvailable()) {
            $this->cleanupFixtures();
        }

        parent::tearDown();
    }

    public function test_parallel_handoff_records_create_exactly_one_delivery_row(): void
    {
        $this->requirePostgresConcurrency();
        $order = $this->paidOrderFixture('race-record@example.test');

        $results = $this->race([
            fn (): bool => app(OrderCredentialMailService::class)->record(Order::query()->findOrFail($order->getKey())) !== null,
            fn (): bool => app(OrderCredentialMailService::class)->record(Order::query()->findOrFail($order->getKey())) !== null,
        ]);

        $this->assertSame([true, true], $results);
        $rows = DB::table('order_notification_events')
            ->where('order_id', $order->getKey())
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('pending', (string) $rows->first()->status);
    }

    public function test_parallel_delivery_attempts_send_exactly_one_email(): void
    {
        $this->requirePostgresConcurrency();
        config()->set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.race.test',
            'mail.mailers.smtp.password' => 'race-secret',
        ]);

        $order = $this->paidOrderFixture('race-delivery@example.test');
        $event = app(OrderCredentialMailService::class)->record($order);
        $this->assertNotNull($event);

        $sendLog = tempnam(sys_get_temp_dir(), 'credential-mail-race');

        $worker = function () use ($event, $sendLog): bool {
            Mail::mailer('smtp')->setSymfonyTransport(new class($sendLog) implements TransportInterface
            {
                public function __construct(private readonly string $sendLog) {}

                public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
                {
                    file_put_contents($this->sendLog, "send\n", FILE_APPEND);

                    return null;
                }

                public function __toString(): string
                {
                    return 'race-scripted';
                }
            });

            app(OrderCredentialMailService::class)->deliver((int) $event->getKey());

            return true;
        };

        try {
            $this->assertSame([true, true], $this->race([$worker, $worker]));

            // Only the worker that won the row lease may reach the transport.
            $this->assertSame("send\n", (string) file_get_contents($sendLog));
        } finally {
            @unlink($sendLog);
        }

        $row = DB::table('order_notification_events')->where('id', $event->getKey())->first();
        $this->assertSame('sent', (string) $row->status);
        $this->assertSame(1, (int) $row->attempts);
    }

    public function test_parallel_delivery_attempts_for_a_terminal_transport_never_send(): void
    {
        $this->requirePostgresConcurrency();
        // A logging transport must fail closed even under contention.
        config()->set('mail.default', 'log');

        $order = $this->paidOrderFixture('race-unsafe@example.test');
        $event = app(OrderCredentialMailService::class)->record($order);
        $this->assertNotNull($event);

        $results = $this->race([
            function () use ($event): bool {
                app(OrderCredentialMailService::class)->deliver((int) $event->getKey());

                return true;
            },
            function () use ($event): bool {
                app(OrderCredentialMailService::class)->deliver((int) $event->getKey());

                return true;
            },
        ]);

        $this->assertSame([true, true], $results);
        $row = DB::table('order_notification_events')->where('id', $event->getKey())->first();
        $this->assertSame('failed', (string) $row->status);
        $this->assertSame(OrderCredentialMailService::ERROR_MAILER_UNSAFE, (string) $row->last_error);
        $this->assertSame(0, (int) $row->attempts);
    }

    private function paidOrderFixture(string $email): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => null, 'first_name' => 'Race Buyer']);
        $this->customerId = (int) $customer->getKey();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);
        $this->productId = (int) $product->getKey();

        $order = Order::factory()->create([
            'customer_id' => $customer->getKey(),
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'notification_email' => $email,
        ]);
        $this->orderId = (string) $order->getKey();

        Payment::factory()->create([
            'order_id' => $order->getKey(),
            'provider' => 'manual',
            'method' => 'QRIS',
            'merchant_ref' => 'RACE-'.$order->getKey(),
            'amount' => 10000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 1,
        ]);

        ProductStockItem::create([
            'product_id' => $product->getKey(),
            'order_item_id' => $item->getKey(),
            'secret' => 'race-account@example.test|race-password',
        ]);

        return $order;
    }

    private function cleanupFixtures(): void
    {
        try {
            if ($this->orderId !== null) {
                DB::table('order_notification_events')->where('order_id', $this->orderId)->delete();
                DB::table('payments')->where('order_id', $this->orderId)->delete();
                DB::table('order_items')->where('order_id', $this->orderId)->delete();
                DB::table('orders')->where('id', $this->orderId)->delete();
            }
            if ($this->productId !== null) {
                DB::table('product_stock_items')->where('product_id', $this->productId)->delete();
                DB::table('products')->where('id', $this->productId)->delete();
            }
            if ($this->customerId !== null) {
                DB::table('customers')->where('id', $this->customerId)->delete();
            }
        } catch (\Throwable) {
            // Fixture cleanup is best effort inside the disposable test database.
        } finally {
            $this->orderId = null;
            $this->customerId = null;
            $this->productId = null;
        }
    }

    private function concurrencyLaneAvailable(): bool
    {
        return DB::getDriverName() === 'pgsql' && (string) env('RUN_CONCURRENCY_TESTS', '') === '1';
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Credential mail races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for credential mail races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for credential mail races.');
        }

        $database = (string) DB::selectOne('select current_database() as database')->database;
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) !== 1) {
            $this->fail("Refusing credential mail races against database [{$database}].");
        }
    }
}
