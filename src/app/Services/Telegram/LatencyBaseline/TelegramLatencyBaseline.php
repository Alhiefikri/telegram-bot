<?php

namespace App\Services\Telegram\LatencyBaseline;

use App\Console\Commands\ReconcileKlikQrisPayments;
use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\KlikQrisEnvironment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use App\Support\QueueTopology;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * Repeatable local latency baseline for the paid receipt path.
 *
 * The runner drives the real payment observation, paid lifecycle, receipt
 * effect pipeline and admin notification owners against disposable
 * PostgreSQL/Redis. Only the external provider/Telegram transports are local
 * fixtures. Scenarios and delay fixtures are recorded in the report.
 */
final class TelegramLatencyBaseline
{
    public const SCENARIOS = [
        'idle',
        'backlog-expired',
        'slow-notification',
        'slow-payment',
        'slow-provider',
        'slow-telegram',
    ];

    public const WORKER_TOPOLOGIES = ['split', 'combined'];

    private const SLOW_TRANSPORT_MS = 500;

    private const SLOW_PAYMENT_PROBE_MS = 3000;

    private KlikQrisCallbackProcessor $observations;

    private KlikQrisNativeQrisService $nativeQris;

    public function __construct(
        private readonly CartService $carts,
        private readonly OrderCheckoutService $checkout,
        private readonly LatencyBaselineReport $report,
    ) {}

    /**
     * @param  array{scenarios: list<string>, batches: int, samples: int, warmup_batches: int, interval_ms: int, timeout_seconds: int, worker_sleep: int, worker_topology: string, provider_latency_ms: int, telegram_latency_ms: int, backlog: int, output: string, run_id: string}  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $this->assertDisposableEnvironment();
        $this->configureLocalProvider();

        $collector = new LatencyBaselineEventCollector;
        $collector->register();

        $provider = new KlikQrisLatencyFixtureProvider;
        app()->instance(KlikQrisProviderAdapter::class, $provider);
        $this->nativeQris = app(KlikQrisNativeQrisService::class);
        $this->observations = app(KlikQrisCallbackProcessor::class);

        $this->ensurePaymentSetting();
        DB::table('failed_jobs')->delete();

        $scenarioResults = [];
        $ordinal = 0;

        foreach ($options['scenarios'] as $scenario) {
            $scenarioResults[$scenario] = $this->runScenario(
                $scenario,
                $options,
                $provider,
                $collector,
                $ordinal,
            );
        }

        return [
            'run_id' => $options['run_id'],
            'generated_at' => now()->toISOString(),
            'contract' => $this->contract(),
            'environment' => $this->environment(),
            'scenarios' => $scenarioResults,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runScenario(
        string $scenario,
        array $options,
        KlikQrisLatencyFixtureProvider $provider,
        LatencyBaselineEventCollector $collector,
        int &$ordinal,
    ): array {
        $config = $this->scenarioConfig($scenario, $options);
        $samples = [];

        for ($batch = 0; $batch < $options['warmup_batches'] + $options['batches']; $batch++) {
            $cold = $batch < $options['warmup_batches'];

            for ($index = 0; $index < $options['samples']; $index++) {
                $ordinal++;
                $samples[] = $this->seedOrder($batch, $index, $cold, $ordinal);
            }
        }

        $this->clearQueues();

        $provider->pendingMerchantRefs = [];
        $providerBacklog = $this->seedProviderBacklog($config, $ordinal, $provider);
        $notificationBacklog = $this->seedNotificationBacklog($config, $ordinal);
        $workers = $this->workers($config, $options);

        $provider->statusDelayMs = $config['provider_latency_ms'];
        foreach ($workers as $worker) {
            $worker->start();
        }

        $interactionProbe = null;

        try {
            if ($config['interaction_probe']) {
                $interactionProbe = $this->runInteractionProbe($config, $options, $collector, $workers, $ordinal);
            }

            foreach ($samples as $sampleIndex => $sample) {
                if ($config['provider_backlog'] > 0) {
                    $this->observeViaReconciliationBacklog($sample, $config, $provider);
                } else {
                    $this->observe($sample, $provider);
                }
                $samples[$sampleIndex] = $sample + [
                    'observation_finished_at' => now()->toISOString(),
                ];

                if ($options['interval_ms'] > 0) {
                    usleep($options['interval_ms'] * 1000);
                }
            }

            $this->waitForBatch(
                $samples,
                $collector,
                array_values($workers),
                $options['timeout_seconds'],
                $interactionProbe['update_id'] ?? null,
            );
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
                $collector->ingestWorkerOutput($worker->output());
            }
        }

        if ($interactionProbe !== null) {
            $interactionProbe = $this->finalizeInteractionProbe(
                $interactionProbe,
                $collector,
                $workers,
            );
        }

        $raw = [];
        foreach ($samples as $sample) {
            $raw[] = $this->buildRawSample($sample, $collector);
        }

        $coldSamples = array_values(array_filter($raw, static fn (array $sample): bool => $sample['cold'] === true));
        $warmSamples = array_values(array_filter($raw, static fn (array $sample): bool => $sample['cold'] === false));

        return [
            'dataset' => [
                'batches' => $options['batches'],
                'warmup_batches' => $options['warmup_batches'],
                'samples_per_batch' => $options['samples'],
                'orders_seeded' => count($samples),
                'provider_history_backlog' => $providerBacklog,
                'notification_backlog' => $notificationBacklog,
                'worker_topology' => $options['worker_topology'],
                'interval_ms' => $options['interval_ms'],
                'timeout_seconds' => $options['timeout_seconds'],
                'product_quantity' => 1,
                'stock_source' => 'local Product Stock Item allocated by checkout',
            ],
            'worker' => reset($workers)->description(),
            'workers' => array_map(
                static fn (LatencyBaselineWorker $worker): array => $worker->description(),
                $workers,
            ),
            'resources' => $this->resourceSummary($workers),
            'interaction_probe' => $interactionProbe,
            'fixture' => [
                'provider' => KlikQrisLatencyFixtureProvider::class,
                'provider_status_delay_ms' => $config['provider_latency_ms'],
                'provider_paid_offset_seconds' => 2,
                'provider_history_status' => $config['provider_backlog'] > 0 ? 'PENDING' : 'SUCCESS',
                'observation_driver' => $config['provider_backlog'] > 0
                    ? 'klikqris:reconcile with a bounded max-payments budget'
                    : 'direct KlikQRIS reconciliation owner call',
                'telegram_transport' => 'App\\Services\\Telegram\\FakeTelegramBotClient',
                'telegram_latency_ms' => $config['telegram_latency_ms'],
                'admin_target' => 'synthetic numeric chat from TELEGRAM_ADMIN_CHAT_ID',
                'queue_probe_latency_ms' => $config['probe_latency_ms'],
            ],
            'cold' => $this->report->summarize($coldSamples),
            'warm' => $this->report->summarize($warmSamples),
            'samples' => $raw,
        ];
    }

    /**
     * @return array{provider_latency_ms: int, telegram_latency_ms: int, provider_backlog: int, notification_backlog: int}
     */
    private function scenarioConfig(string $scenario, array $options): array
    {
        $base = [
            'provider_latency_ms' => $options['provider_latency_ms'],
            'telegram_latency_ms' => $options['telegram_latency_ms'],
            'provider_backlog' => 0,
            'notification_backlog' => 0,
            'interaction_probe' => false,
            'probe_latency_ms' => 0,
        ];

        return match ($scenario) {
            'idle' => $base,
            'backlog-expired' => array_replace($base, ['provider_backlog' => max(1, $options['backlog'])]),
            'slow-notification' => array_replace($base, [
                'telegram_latency_ms' => max(self::SLOW_TRANSPORT_MS, $options['telegram_latency_ms']),
                'notification_backlog' => 1,
            ]),
            'slow-payment' => array_replace($base, [
                'interaction_probe' => true,
                'probe_latency_ms' => self::SLOW_PAYMENT_PROBE_MS,
            ]),
            'slow-provider' => array_replace($base, [
                'provider_latency_ms' => max(self::SLOW_TRANSPORT_MS, $options['provider_latency_ms']),
            ]),
            'slow-telegram' => array_replace($base, [
                'telegram_latency_ms' => max(self::SLOW_TRANSPORT_MS, $options['telegram_latency_ms']),
            ]),
            default => throw new RuntimeException('Unknown latency baseline scenario: '.$scenario),
        };
    }

    /**
     * @return array<string, LatencyBaselineWorker>
     */
    private function workers(array $config, array $options): array
    {
        $maxTime = max(120, $options['timeout_seconds'] * 2);
        $baseEnvironment = $this->workerEnvironment($config);
        $fastTelegramEnvironment = array_replace($baseEnvironment, [
            'DOGFOOD_FAKE_TELEGRAM_LATENCY_US' => '0',
        ]);
        $topology = [];

        if ($options['worker_topology'] === 'combined') {
            $topology['receipt-and-notifications'] = [
                'queue' => QueueTopology::NOTIFICATIONS.','.TelegramOutboundEffectService::RECEIPT_QUEUE,
                'environment' => $baseEnvironment,
            ];

            if ($config['interaction_probe']) {
                $topology['payments-and-interactions'] = [
                    'queue' => QueueTopology::PAYMENTS.','.QueueTopology::INTERACTIONS,
                    'environment' => $baseEnvironment,
                ];
            }
        } else {
            // The slow-notification scenario injects delay only into the
            // unrelated notification worker. Slowing the receipt worker too
            // would measure Telegram transport latency, not queue contention.
            $receiptEnvironment = $config['notification_backlog'] > 0
                ? $fastTelegramEnvironment
                : $baseEnvironment;

            $topology['receipts'] = [
                'queue' => TelegramOutboundEffectService::RECEIPT_QUEUE,
                'environment' => $receiptEnvironment,
            ];
            $topology['notifications'] = [
                'queue' => QueueTopology::NOTIFICATIONS,
                'environment' => $baseEnvironment,
            ];

            if ($config['interaction_probe']) {
                $topology['interactions'] = [
                    'queue' => QueueTopology::INTERACTIONS,
                    'environment' => $baseEnvironment,
                ];
                $topology['payments'] = [
                    'queue' => QueueTopology::PAYMENTS,
                    'environment' => $baseEnvironment,
                ];
            }
        }

        $workers = [];
        foreach ($topology as $name => $worker) {
            $workers[$name] = new LatencyBaselineWorker(
                $worker['environment'],
                [
                    'queue' => $worker['queue'],
                    'sleep' => $options['worker_sleep'],
                    'tries' => 3,
                    'timeout' => 60,
                    'max_time' => $maxTime,
                ],
            );
        }

        return $workers;
    }

    /**
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array{worker_count: int, worker_peak_rss_total_bytes: int, worker_peak_rss_max_bytes: int}
     */
    private function resourceSummary(array $workers): array
    {
        $rss = array_values(array_filter(array_map(
            static fn (LatencyBaselineWorker $worker): ?int => $worker->description()['peak_rss_bytes'],
            $workers,
        ), static fn (?int $value): bool => $value !== null));

        return [
            'worker_count' => count($workers),
            'worker_peak_rss_total_bytes' => array_sum($rss),
            'worker_peak_rss_max_bytes' => $rss === [] ? 0 : max($rss),
        ];
    }

    /**
     * Occupy the payments queue with a local probe, then enqueue a real
     * Telegram update behind it. The probe never calls a provider.
     *
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array<string, mixed>
     */
    private function runInteractionProbe(
        array $config,
        array $options,
        LatencyBaselineEventCollector $collector,
        array $workers,
        int $ordinal,
    ): array {
        $marker = 'slow-payment-'.$options['run_id'].'-'.$ordinal;
        $probeCorrelation = DogfoodTiming::correlation($marker);

        SlowPaymentVerificationProbe::dispatch($config['probe_latency_ms'], $marker)
            ->onQueue(QueueTopology::PAYMENTS);

        $probeStarted = $this->waitForEvent(
            $collector,
            $workers,
            'dogfood.telegram.queue_probe_started',
            $probeCorrelation,
            10.0,
        );

        $chatId = 8_800_000 + $ordinal;
        $customer = Customer::query()->create([
            'telegram_id' => $chatId,
            'first_name' => 'Latency Update',
            'locale' => 'en',
        ]);
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(1_000_000_000, 2_000_000_000),
            'customer_id' => $customer->getKey(),
            'payload' => ['message' => [
                'message_id' => 1,
                'date' => now()->timestamp,
                'text' => '/start',
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'Latency Update',
                    'language_code' => 'en',
                ],
            ]],
            'processed_at' => null,
            'created_at' => now(),
        ]);

        $enqueuedAt = now()->toISOString();
        ProcessTelegramUpdate::dispatch($update->id, $enqueuedAt)
            ->onQueue(QueueTopology::INTERACTIONS);

        $pickup = $this->waitForEvent(
            $collector,
            $workers,
            'dogfood.telegram.job_picked_up',
            DogfoodTiming::correlation($update->id),
            10.0,
        );

        return [
            'update_id' => (int) $update->id,
            'correlation_id' => DogfoodTiming::correlation($update->id),
            'probe_correlation_id' => $probeCorrelation,
            'probe_latency_ms' => (int) $config['probe_latency_ms'],
            'probe_started' => $probeStarted !== null,
            'interaction_queue' => QueueTopology::INTERACTIONS,
            'payment_queue' => QueueTopology::PAYMENTS,
            'worker_topology' => $options['worker_topology'],
            'enqueued_at' => $enqueuedAt,
            'queue_wait_ms' => is_numeric($pickup['context']['queue_wait_ms'] ?? null)
                ? (int) $pickup['context']['queue_wait_ms']
                : null,
            'picked_up_at' => $pickup['context']['job_picked_up_at'] ?? null,
            'picked_up' => $pickup !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array<string, mixed>
     */
    private function finalizeInteractionProbe(
        array $probe,
        LatencyBaselineEventCollector $collector,
        array $workers,
    ): array {
        $this->pumpWorkers($collector, $workers);
        $update = TelegramUpdate::query()->find($probe['update_id']);

        $finished = $collector->first(
            'dogfood.telegram.queue_probe_finished',
            (string) $probe['probe_correlation_id'],
        );
        $pickupToProcessed = $probe['picked_up_at'] !== null && $update?->processed_at !== null
            ? $this->diffMs((string) $probe['picked_up_at'], $update->processed_at->toISOString())
            : null;

        return [
            ...$probe,
            'processed' => $update?->processed_at !== null,
            'terminal_failed' => $update?->terminal_failed_at !== null,
            'pickup_to_processed_ms' => $pickupToProcessed,
            'probe_finished' => $finished !== null,
            'complete' => ($probe['probe_started'] ?? false) === true
                && $probe['picked_up'] === true
                && $finished !== null
                && $update?->processed_at !== null
                && $update?->terminal_failed_at === null,
        ];
    }

    /**
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array<string, mixed>|null
     */
    private function waitForEvent(
        LatencyBaselineEventCollector $collector,
        array $workers,
        string $event,
        string $correlation,
        float $timeoutSeconds,
    ): ?array {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $this->pumpWorkers($collector, $workers);

            $match = $collector->first($event, $correlation);
            if ($match !== null) {
                return $match;
            }

            usleep(50_000);
        }

        return $collector->first($event, $correlation);
    }

    /**
     * @param  array<string, LatencyBaselineWorker>  $workers
     */
    private function pumpWorkers(LatencyBaselineEventCollector $collector, array $workers): void
    {
        foreach ($workers as $worker) {
            $worker->pump();
            $collector->ingestWorkerOutput($worker->output());
        }
    }

    /**
     * @return array{order_id: string, payment_id: string, order_correlation_id: string, payment_correlation_id: string, cold: bool, batch: int, index: int, observation_started_at: string}
     */
    private function seedOrder(int $batch, int $index, bool $cold, int $ordinal): array
    {
        $customer = Customer::query()->create([
            'telegram_id' => 8_500_000 + $ordinal,
            'first_name' => 'Latency Baseline',
            'locale' => 'en',
        ]);

        $product = Product::query()->create([
            'name' => 'Baseline Product '.$ordinal,
            'description' => 'Synthetic latency baseline fixture.',
            'price_amount' => 10_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $product->forceFill(['stock' => 2, 'reserved_stock' => 0])->save();

        foreach ([1, 2] as $credential) {
            ProductStockItem::query()->create([
                'product_id' => $product->getKey(),
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => [
                    'version' => 1,
                    'email' => 'latency-'.$ordinal.'-'.$credential.'@example.test',
                    'password' => 'synthetic-baseline-password-'.$ordinal.'-'.$credential,
                    'access_method' => CredentialAccessMethod::None->value,
                    'access_value' => null,
                    'tutorial_override' => null,
                ],
            ]);
        }

        $cart = $this->carts->activeCartFor($customer);
        $this->carts->addItem($cart, $product, 1);
        $order = $this->checkout->checkout($cart, CheckoutPaymentIntents::klikQris());
        $payment = $order->payments()->sole();

        $lifecycle = $this->nativeQris->lifecycle($payment);
        if (! $lifecycle->isPayable()) {
            $payment->refresh();
            throw new RuntimeException(sprintf(
                'Latency baseline fixture order did not produce a payable provider quote (outcome=%s, state=%s, error=%s).',
                $lifecycle->outcome()->value,
                (string) $payment->provider_create_state,
                (string) $payment->provider_create_last_error,
            ));
        }

        $payment->refresh();

        return [
            'order_id' => (string) $order->getKey(),
            'payment_id' => (string) $payment->getKey(),
            'order_correlation_id' => DogfoodTiming::correlation($order->getKey()),
            'payment_correlation_id' => DogfoodTiming::correlation($payment->getKey()),
            'cold' => $cold,
            'batch' => $batch,
            'index' => $index,
            'observation_started_at' => '',
        ];
    }

    /**
     * @param  array{provider_latency_ms: int, telegram_latency_ms: int, provider_backlog: int, notification_backlog: int}  $config
     */
    private function seedProviderBacklog(array $config, int $ordinal, KlikQrisLatencyFixtureProvider $provider): int
    {
        if ($config['provider_backlog'] < 1) {
            return 0;
        }

        for ($index = 1; $index <= $config['provider_backlog']; $index++) {
            $customer = Customer::query()->create([
                'telegram_id' => 8_700_000 + $ordinal + $index,
                'first_name' => 'Latency Provider History',
                'locale' => 'en',
            ]);
            $createdAt = now()->subMinutes(30)->addSeconds($index);
            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'invoice_number' => 'LATENCY-HISTORY-'.$ordinal.'-'.$index,
                'order_status' => OrderStatus::PendingPayment,
                'payment_status' => PaymentStatus::Pending,
                'total_amount' => 10_000,
                'currency' => 'IDR',
                'expires_at' => now()->subMinutes(10),
                'created_at' => $createdAt,
            ]);
            $merchantRef = 'LATENCY-HISTORY-PAY-'.$ordinal.'-'.$index;
            $payment = Payment::query()->create([
                'order_id' => $order->getKey(),
                'provider' => 'klikqris',
                'merchant_ref' => $merchantRef,
                'method' => 'QRIS',
                'amount' => 10_000,
                'currency' => 'IDR',
                'status' => PaymentStatus::Pending,
                'provider_total_payment' => 10_016,
                'provider_fee' => 16,
                'provider_payment_method' => 'qris',
                'klikqris_transaction_signature' => 'latency-history-signature-'.$ordinal.'-'.$index,
                'provider_qris_url' => 'https://klikqris.com/storage/sandbox/qris_latency_history.png',
                'provider_payload' => ['data' => [
                    'order_id' => $merchantRef,
                    'amount' => 10_000,
                    'total_amount' => 10_016,
                    'status' => 'PENDING',
                    'qris_url' => 'https://klikqris.com/storage/sandbox/qris_latency_history.png',
                    'expired_at' => now()->subMinutes(10)->toIso8601ZuluString(),
                ]],
                'provider_created_at' => $createdAt,
                'provider_expires_at' => now()->subMinutes(10),
                'expired_at' => now()->subMinutes(10),
                'created_at' => $createdAt,
            ]);
            DB::table('orders')->where('id', $order->getKey())->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            DB::table('payments')->where('id', $payment->getKey())->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $provider->pendingMerchantRefs[] = $merchantRef;
        }

        return $config['provider_backlog'];
    }

    /**
     * @param  array{provider_latency_ms: int, telegram_latency_ms: int, provider_backlog: int, notification_backlog: int}  $config
     */
    private function seedNotificationBacklog(array $config, int $ordinal): int
    {
        if ($config['notification_backlog'] < 1) {
            return 0;
        }

        for ($index = 1; $index <= $config['notification_backlog']; $index++) {
            $customer = Customer::query()->create([
                'telegram_id' => 8_600_000 + $ordinal + $index,
                'first_name' => 'Latency Backlog',
                'locale' => 'en',
            ]);
            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'invoice_number' => 'LATENCY-BACKLOG-'.$ordinal.'-'.$index,
                'order_status' => OrderStatus::Expired,
                'payment_status' => PaymentStatus::Expired,
                'total_amount' => 10_000,
                'currency' => 'IDR',
                'expires_at' => now()->subHour(),
            ]);
            Payment::query()->create([
                'order_id' => $order->getKey(),
                'provider' => 'manual',
                'merchant_ref' => 'LATENCY-BACKLOG-PAY-'.$ordinal.'-'.$index,
                'method' => 'QR_MANUAL',
                'amount' => 10_000,
                'currency' => 'IDR',
                'status' => PaymentStatus::Expired,
                'expired_at' => now()->subHour(),
            ]);

            SendAdminOrderExpiredNotification::dispatch((string) $order->getKey(), now()->toISOString())
                ->onQueue(QueueTopology::NOTIFICATIONS);
        }

        return $config['notification_backlog'];
    }

    /** @param array<string, mixed> $sample */
    private function observe(array &$sample, KlikQrisLatencyFixtureProvider $provider): void
    {
        $payment = Payment::query()->findOrFail($sample['payment_id']);
        $provider->providerPaidAt = CarbonImmutable::now()->subSeconds(2);
        $sample['observation_started_at'] = now()->toISOString();

        $transaction = $provider->transactionStatus($payment);
        $this->observations->processReconciliationForPayment($payment, $transaction);
    }

    /**
     * Drive the real bounded reconciliation command. The oldest provider
     * history consumes the max-payments budget first, so the measured payment
     * is observed only after the backlog has had its turn.
     *
     * @param  array{provider_backlog: int}  $config
     */
    private function observeViaReconciliationBacklog(
        array &$sample,
        array $config,
        KlikQrisLatencyFixtureProvider $provider,
    ): void {
        $sample['observation_started_at'] = now()->toISOString();
        $provider->providerPaidAt = CarbonImmutable::now()->subSeconds(2);
        $sweeps = 0;

        // Start every observation from the oldest class cursors so repeated
        // samples cannot inherit a previous sweep position. The pending
        // backlog remains ahead of the measured payment in the active class.
        ReconcileKlikQrisPayments::forgetCursors(KlikQrisEnvironment::runtime());

        for ($sweep = 1; $sweep <= 4; $sweep++) {
            Artisan::call('klikqris:reconcile', [
                '--chunk' => 100,
                // Sweep 1 spends the whole budget on provider history. Later
                // sweeps inspect only the next candidate, so an observation
                // call cannot silently pay other measured samples ahead.
                '--max-payments' => $sweep === 1 ? max(1, $config['provider_backlog']) : 1,
            ], new NullOutput);
            $sweeps = $sweep;

            if (Payment::query()->find($sample['payment_id'])?->status === PaymentStatus::Paid) {
                break;
            }
        }

        $sample['observation_sweeps'] = $sweeps;
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     * @param  list<LatencyBaselineWorker>  $workers
     */
    private function waitForBatch(
        array $samples,
        LatencyBaselineEventCollector $collector,
        array $workers,
        int $timeoutSeconds,
        ?int $updateId = null,
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $this->pumpWorkers($collector, $workers);

            $complete = true;
            foreach ($samples as $sample) {
                $correlation = $sample['order_correlation_id'];
                if ($collector->first('dogfood.telegram.admin_paid_notification_acknowledged', $correlation) === null) {
                    $complete = false;
                    break;
                }
            }

            if ($complete && $updateId !== null) {
                $update = TelegramUpdate::query()->find($updateId);
                $complete = $update !== null
                    && ($update->processed_at !== null || $update->terminal_failed_at !== null);
            }

            if ($complete) {
                return;
            }

            if (! $this->anyWorkerRunning($workers)) {
                return;
            }

            usleep(100_000);
        }
    }

    /** @param  list<LatencyBaselineWorker>  $workers */
    private function anyWorkerRunning(array $workers): bool
    {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function buildRawSample(array $sample, LatencyBaselineEventCollector $collector): array
    {
        $correlation = $sample['order_correlation_id'];
        $observed = $collector->first('dogfood.telegram.payment_observed', $correlation);
        $committed = $collector->first('dogfood.telegram.paid_committed', $correlation);
        $pickups = $collector->matching('dogfood.telegram.receipt_job_picked_up', $correlation);
        $pickup = $pickups[0] ?? null;
        $firstAcks = $collector->matching('dogfood.telegram.receipt_first_credential_acknowledged', $correlation);
        $firstAck = $firstAcks[0] ?? null;
        $completed = $collector->first('dogfood.telegram.receipt_completed', $correlation);
        $admin = $collector->first('dogfood.telegram.admin_paid_notification_acknowledged', $correlation);

        $observedAt = $this->timestamp($observed['context']['application_observed_at'] ?? null);
        $providerPaidAt = $this->timestamp($observed['context']['provider_paid_at'] ?? null);
        $paidCommittedAt = $this->timestamp($committed['context']['paid_committed_at'] ?? null);
        $enqueuedAt = $this->timestamp($pickup['context']['job_enqueued_at'] ?? null);
        $pickedUpAt = $this->timestamp($pickup['context']['job_picked_up_at'] ?? null);
        $firstAckAt = $this->timestamp($firstAck['context']['telegram_acknowledged_at'] ?? null);
        $completedAt = $this->timestamp($completed['context']['receipt_completed_at'] ?? null);
        $adminAckAt = $this->timestamp($admin['context']['telegram_acknowledged_at'] ?? null);

        $queueWait = $pickup['context']['queue_wait_ms'] ?? null;
        $queueWait = is_int($queueWait) || is_float($queueWait)
            ? (int) $queueWait
            : $this->diffMs($enqueuedAt, $pickedUpAt);

        $order = Order::query()->withCount('orderItems')->find($sample['order_id']);
        $payment = Payment::query()->find($sample['payment_id']);
        $effects = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', $sample['order_id'])
            ->get(['effect_type', 'state']);

        $effectStates = [];
        foreach ($effects->groupBy(fn (TelegramOutboundEffect $effect): string => $effect->effect_type.'@'.$effect->state->value) as $key => $group) {
            $effectStates[$key] = $group->count();
        }

        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$sample['order_id'].'%')
            ->count();

        $raw = [
            'cold' => $sample['cold'],
            'batch' => $sample['batch'],
            'index' => $sample['index'],
            'order_correlation_id' => $correlation,
            'payment_correlation_id' => $sample['payment_correlation_id'],
            'provider_observation_ms' => $this->diffMs($this->timestamp($sample['observation_started_at'] ?? null), $observedAt),
            'provider_paid_to_observation_ms' => $this->diffMs($providerPaidAt, $observedAt),
            'observation_to_paid_commit_ms' => $this->diffMs($observedAt, $paidCommittedAt),
            'paid_commit_to_enqueue_ms' => $this->diffMs($paidCommittedAt, $enqueuedAt),
            'queue_wait_ms' => $queueWait,
            'pickup_to_first_credential_ack_ms' => $this->diffMs($pickedUpAt, $firstAckAt),
            'pickup_to_receipt_completion_ms' => $this->diffMs($pickedUpAt, $completedAt),
            'receipt_completion_to_admin_notice_ms' => $this->diffMs($completedAt, $adminAckAt),
            'e2e_observation_to_admin_notice_ms' => $this->diffMs($observedAt, $adminAckAt),
            'observation_sweeps' => $sample['observation_sweeps'] ?? 1,
            'enqueue_stage' => $pickup['context']['enqueue_stage'] ?? 'unknown',
            'enqueue_reason' => $pickup['context']['enqueue_reason'] ?? 'unknown',
            'queue_wait_unknown' => $queueWait === null,
            'receipt_pickups' => count($pickups),
            'duplicate_acknowledgements' => max(0, count($firstAcks) - 1),
            'failed_jobs' => $failedJobs,
            'payment_status' => $payment?->status?->value ?? 'unknown',
            'order_status' => $order?->order_status?->value ?? 'unknown',
            'receipt_sent' => $order?->telegram_receipt_sent_at !== null,
            'admin_notice_sent' => $order?->admin_paid_order_notified_at !== null,
            'order_item_count' => (int) ($order?->order_items_count ?? 0),
            'effect_states' => $effectStates,
        ];

        $raw['missing_boundaries'] = array_keys(array_filter(
            $raw,
            static fn (mixed $value, string $key): bool => array_key_exists($key, LatencyBaselineReport::BOUNDARIES) && $value === null,
            ARRAY_FILTER_USE_BOTH,
        ));
        $raw['complete'] = $raw['missing_boundaries'] === [];

        return $raw;
    }

    private function timestamp(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function diffMs(?string $from, ?string $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        try {
            return round(CarbonImmutable::parse($from)->diffInMilliseconds(CarbonImmutable::parse($to), true), 2);
        } catch (Throwable) {
            return null;
        }
    }

    private function clearQueues(): void
    {
        foreach (QueueTopology::operational() as $queue) {
            Artisan::call('queue:clear', [
                'connection' => 'redis',
                '--queue' => $queue,
                '--force' => true,
            ]);
        }

        Cache::flush();
    }

    /** @return array<string, string> */
    private function workerEnvironment(array $config): array
    {
        return [
            'APP_ENV' => (string) config('app.env'),
            'APP_KEY' => (string) config('app.key'),
            'APP_ALLOW_DOGFOOD_RUNTIME' => 'true',
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'array',
            'DOGFOOD_TIMING_ENABLED' => 'true',
            'DOGFOOD_TIMING_LOG_CHANNEL' => 'stderr',
            'DOGFOOD_FAKE_TELEGRAM_OUTBOUND' => 'true',
            'DOGFOOD_FAKE_TELEGRAM_LATENCY_US' => (string) ($config['telegram_latency_ms'] * 1000),
            'TELEGRAM_BOT_TOKEN' => (string) config('services.telegram.token'),
            'TELEGRAM_ADMIN_CHAT_ID' => (string) config('services.telegram.admin_telegram_chat_id'),
            'LOG_LEVEL' => 'debug',
        ];
    }

    private function configureLocalProvider(): void
    {
        config()->set('services.klikqris.sandbox_enabled', true);
        config()->set('services.klikqris.sandbox_base_url', 'https://klikqris.com/api/sandbox');
        config()->set('services.klikqris.sandbox_api_key', 'latency-baseline-fixture-key');
        config()->set('services.klikqris.sandbox_merchant_id', 'latency-baseline-fixture-merchant');
        config()->set('services.klikqris.qris_ttl_minutes', 15);
        config()->set('services.klikqris.status_max_attempts', 1);
        config()->set('services.klikqris.status_retry_delay_ms', 0);
        config()->set('services.klikqris.reconcile_terminal_lookback_hours', 72);
        config()->set('services.klikqris.reconcile_max_execution_seconds', 45);
        config()->set('services.klikqris.http_timeout_seconds', 1.0);
        config()->set('services.klikqris.http_connect_timeout_seconds', 1.0);
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.dogfood_timing.log_channel', null);
    }

    private function ensurePaymentSetting(): void
    {
        if (ManualPaymentSetting::query()->where('is_active', true)->doesntExist()) {
            ManualPaymentSetting::query()->create([
                'payment_mode' => 'klikqris',
                'is_active' => true,
            ]);
        }
    }

    private function assertDisposableEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The latency baseline may only run with APP_ENV=local or testing.');
        }

        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('The latency baseline requires a disposable PostgreSQL connection.');
        }

        if (config('queue.default') !== 'redis') {
            throw new RuntimeException('The latency baseline requires the Redis queue connection.');
        }

        if (config('cache.default') !== 'redis') {
            throw new RuntimeException('The latency baseline requires the Redis cache store for cross-process worker locks.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(baseline|test)/i', $database) !== 1) {
            throw new RuntimeException('Refusing to run: DB_DATABASE must name a disposable baseline/test database.');
        }

        if (! (bool) config('services.dogfood_timing.enabled')) {
            throw new RuntimeException('The latency baseline requires DOGFOOD_TIMING_ENABLED=true.');
        }

        if (! (bool) config('services.dogfood_timing.fake_telegram_outbound')) {
            throw new RuntimeException('The latency baseline requires the local fake Telegram transport; real sends are not allowed.');
        }

        if (blank(config('services.telegram.token'))) {
            throw new RuntimeException('The latency baseline needs a synthetic Telegram token for the admin-notice fixture.');
        }

        if (preg_match('/^[1-9][0-9]*$/', (string) config('services.telegram.admin_telegram_chat_id')) !== 1) {
            throw new RuntimeException('The latency baseline needs a synthetic numeric TELEGRAM_ADMIN_CHAT_ID.');
        }
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return [
            'note' => 'Local repeatable baseline; Telegram acknowledgement is not a read receipt and these percentiles are not a production SLO.',
            'boundaries' => LatencyBaselineReport::BOUNDARIES,
            'percentile' => 'nearest rank over available samples; unknown timestamps stay unknown',
            'cold_start' => 'warm-up batches are reported separately and excluded from warmed percentiles',
        ];
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        $database = 'pgsql';
        try {
            $version = DB::selectOne('select version() as version');
            $database = 'pgsql '.($version->version ?? '');
        } catch (Throwable) {
            $database = 'pgsql (version unavailable)';
        }

        $redis = 'redis (version unavailable)';
        try {
            $info = Redis::connection()->command('info');
            $redisVersion = is_array($info)
                ? ($info['redis_version'] ?? null)
                : (preg_match('/^redis_version:(.+)$/mi', (string) $info, $match) === 1 ? trim($match[1]) : null);
            $redis = $redisVersion === null ? 'redis' : 'redis '.$redisVersion;
        } catch (Throwable) {
            $redis = 'redis';
        }

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'app_env' => (string) config('app.env'),
            'timezone' => (string) config('app.timezone'),
            'database' => $database,
            'redis' => $redis,
            'queue_connection' => (string) config('queue.default'),
            'cache_store' => (string) config('cache.default'),
            'cpu_count' => $this->cpuCount(),
            'memory_limit' => (string) ini_get('memory_limit'),
            'os' => PHP_OS_FAMILY,
            'baseline_image' => (string) (getenv('LATENCY_BASELINE_IMAGE') ?: 'unknown'),
        ];
    }

    private function cpuCount(): ?int
    {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && preg_match_all('/^processor\s*:/mi', $cpuInfo, $matches) > 0) {
            return count($matches[0]);
        }

        return null;
    }
}
