<?php

namespace App\Services;

use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderCredentialMailDeliveryException;
use App\Jobs\SendOrderCredentialMail;
use App\Mail\OrderCredentialMail;
use App\Models\Order;
use App\Models\OrderNotificationEvent;
use App\Services\Credentials\CredentialDelivery;
use App\Services\Credentials\CredentialDeliveryAccount;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\Credentials\CredentialDeliveryProductGroup;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Durable delivery of paid account data to the checkout email address.
 *
 * The database row is the only authority for delivery state: the queue is a
 * wakeup path, the job payload carries the event ID only, and the recipient is
 * the address snapshotted when the event was created. Every state transition
 * is a conditional update guarded by the locked claim, so a duplicate queue
 * delivery can never send a second copy of the same attempt.
 */
final class OrderCredentialMailService
{
    public const ERROR_MAILER_UNSAFE = 'mailer_unsafe';

    public const ERROR_MAILER_UNAVAILABLE = 'mailer_unavailable';

    public const ERROR_ORDER_NOT_ELIGIBLE = 'order_not_eligible';

    public const ERROR_RECIPIENT_MISSING = 'recipient_missing';

    public const ERROR_FULFILLMENT_PROCESSING = 'fulfillment_processing';

    public const ERROR_CREDENTIALS_UNAVAILABLE = 'credentials_unavailable';

    public const ERROR_MAIL_DELIVERY_FAILED = 'mail_delivery_failed';

    public const ERROR_ATTEMPT_LIMIT = 'attempt_limit';

    public function __construct(
        private readonly CredentialDeliveryPresentation $credentials,
        private readonly CredentialDeliveryEligibility $eligibility,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
    ) {}

    /**
     * Create the durable handoff when the order can actually deliver paid
     * credentials. Safe to call from within the payment transaction: it only
     * writes the row and hands the queue dispatch to after-commit.
     */
    public function record(Order $order): ?OrderNotificationEvent
    {
        $recipient = $this->normalizeEmail($order->notification_email);
        if ($recipient === null || ! $this->isDeliverable($order)) {
            return null;
        }

        $event = OrderNotificationEvent::query()->firstOrCreate(
            [
                'order_id' => $order->getKey(),
                'event_type' => GuestOrderNotificationEvent::CredentialsReady->value,
            ],
            [
                'recipient_email' => $recipient,
                'status' => OrderNotificationEvent::STATUS_PENDING,
                'next_attempt_at' => now(),
            ],
        );

        if ($event->wasRecentlyCreated) {
            $this->dispatch((int) $event->getKey());
        }

        return $event;
    }

    /**
     * Re-arm an existing delivery after a terminal state or an in-flight
     * stall. The recipient is never taken from the caller: the stored snapshot
     * wins, so a session that replayed checkout cannot redirect the secrets.
     */
    public function rearm(Order $order): ?OrderNotificationEvent
    {
        $event = $this->eventFor($order);
        if ($event === null || ! $this->isDeliverable($order)) {
            return null;
        }

        $rearmed = DB::transaction(function () use ($event): ?OrderNotificationEvent {
            $locked = OrderNotificationEvent::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->status === OrderNotificationEvent::STATUS_PENDING
                || $locked->status === OrderNotificationEvent::STATUS_SENDING) {
                // In-flight work (including our own lease) is left untouched.
                return null;
            }

            $locked->forceFill([
                'status' => OrderNotificationEvent::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'delivery_started_at' => null,
                'failed_at' => null,
                'last_error' => null,
            ])->save();

            return $locked;
        }, 5);

        if ($rearmed === null) {
            return null;
        }

        $this->dispatch((int) $rearmed->getKey());

        return $rearmed;
    }

    public function deliver(int|string $eventId): void
    {
        if ($this->mailerIsUnsafe()) {
            $this->markUnsafeMailer($eventId);

            return;
        }

        if (! $this->mailerIsReady()) {
            $this->deferWithoutAttempt($eventId, self::ERROR_MAILER_UNAVAILABLE, $this->mailerRetrySeconds());

            return;
        }

        $claim = $this->claim($eventId);
        if ($claim === null) {
            return;
        }

        $event = $claim['event'];
        $order = Order::query()
            ->with(['payments', 'orderItems.stockItems.supplierAccountUnit', 'orderItems.product'])
            ->whereKey($event->order_id)
            ->first();

        if ($order === null) {
            $this->finish($claim, [
                'status' => OrderNotificationEvent::STATUS_CANCELLED,
                'next_attempt_at' => null,
                'delivery_started_at' => null,
                'last_error' => self::ERROR_ORDER_NOT_ELIGIBLE,
            ]);

            return;
        }

        $recipient = $this->normalizeEmail($event->recipient_email)
            ?? $this->normalizeEmail($order->notification_email);

        if ($recipient === null) {
            $this->finish($claim, [
                'status' => OrderNotificationEvent::STATUS_CANCELLED,
                'next_attempt_at' => null,
                'delivery_started_at' => null,
                'last_error' => self::ERROR_RECIPIENT_MISSING,
            ]);

            return;
        }

        $rejection = $this->eligibility->rejectionReason($order);
        if ($rejection === self::ERROR_FULFILLMENT_PROCESSING) {
            $this->defer($claim, self::ERROR_FULFILLMENT_PROCESSING);

            return;
        }

        if ($rejection !== null) {
            $this->finish($claim, [
                'status' => OrderNotificationEvent::STATUS_CANCELLED,
                'next_attempt_at' => null,
                'delivery_started_at' => null,
                'last_error' => self::ERROR_ORDER_NOT_ELIGIBLE,
            ]);
            $this->logState('storefront.credential_mail_cancelled', $order, $claim['attempts'], $rejection);

            return;
        }

        $delivery = $this->credentials->forOrder($order);
        if ($delivery->isEmpty()) {
            $this->defer($claim, self::ERROR_CREDENTIALS_UNAVAILABLE);

            return;
        }

        try {
            Mail::to($recipient)->send($this->mailFor($order, $delivery));
        } catch (Throwable $exception) {
            // Provider text can echo the recipient or the message body, so the
            // exception message never reaches the log or the stored error.
            $this->failDeliveryAttempt($claim, $exception);

            return;
        }

        $this->finish($claim, [
            'status' => OrderNotificationEvent::STATUS_SENT,
            'sent_at' => now(),
            'delivery_started_at' => null,
            'next_attempt_at' => null,
            'failed_at' => null,
            'last_error' => null,
        ]);
        $this->logState('storefront.credential_mail_sent', $order, $claim['attempts'], null);
    }

    /**
     * Re-dispatch retained work and create handoffs for eligible paid orders
     * that never received one (for example because a lifecycle hook was not
     * reached). Bounded per run; both scans are index-backed.
     *
     * @return array{dispatched: int, created: int, scanned: int}
     */
    public function recover(int $limit = 100, int $sinceHours = 72): array
    {
        $limit = max(1, min(1000, $limit));
        $sinceHours = max(1, min(24 * 30, $sinceHours));

        $dispatched = 0;
        $pending = OrderNotificationEvent::query()
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->where('status', OrderNotificationEvent::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($pending as $id) {
            if ($this->dispatch((int) $id)) {
                $dispatched++;
            }
        }

        $created = 0;
        $scanned = 0;
        $orders = Order::query()
            ->whereNotNull('notification_email')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereIn('order_status', [OrderStatus::Processing->value, OrderStatus::Completed->value])
            ->where('created_at', '>=', now()->subHours($sinceHours))
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('order_notification_events')
                    ->whereColumn('order_notification_events.order_id', 'orders.id')
                    ->where('order_notification_events.event_type', GuestOrderNotificationEvent::CredentialsReady->value);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            $scanned++;

            if ($this->record($order) !== null) {
                $created++;
            }
        }

        return [
            'dispatched' => $dispatched,
            'created' => $created,
            'scanned' => $scanned,
        ];
    }

    public function eventFor(Order $order): ?OrderNotificationEvent
    {
        return OrderNotificationEvent::query()
            ->where('order_id', $order->getKey())
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->first();
    }

    /** True when the order can receive paid credentials over email right now. */
    public function isDeliverable(Order $order): bool
    {
        return $this->eligibility->allows($order)
            && $this->eligibility->hasAllocatedCredentials($order);
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('services.storefront.credential_email.max_attempts', 5));
    }

    /**
     * Result of checking the configured mailer chain: ready, unconfigured, or
     * unsafe. Unsafe transports (log/array) would persist credentials into log
     * storage, so delivery refuses them instead of degrading.
     */
    public function mailerState(): string
    {
        return $this->resolveMailerState((string) config('mail.default', 'log'));
    }

    public function mailerIsReady(): bool
    {
        return $this->mailerState() === 'ready';
    }

    public function mailerIsUnsafe(): bool
    {
        return $this->mailerState() === 'unsafe';
    }

    public static function maskedRecipient(?string $email): ?string
    {
        $email = is_string($email) ? trim($email) : '';
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = mb_substr($local, 0, 1);

        return $local.'***@'.$domain;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        return $email === '' ? null : $email;
    }

    private function mailFor(Order $order, CredentialDelivery $delivery): OrderCredentialMail
    {
        return new OrderCredentialMail(
            invoiceNumber: (string) $order->invoice_number,
            groups: $this->groups($delivery),
            usageGuides: $delivery->usageGuides(),
            invoiceUrl: $this->absoluteUrl($this->invoiceUrls->show($order)),
            orderAccessUrl: $this->absoluteUrl(route('storefront.order-access.create')),
            defaultInstruction: (string) config('storefront.fulfillment.automatic_default_instruction'),
        );
    }

    /**
     * @return list<array{product_name: string, accounts: list<array<string, mixed>>, delivery_note: ?string, usage_guide: null|array{title: string, url: string}}>
     */
    private function groups(CredentialDelivery $delivery): array
    {
        return array_map(function (CredentialDeliveryProductGroup $group): array {
            return [
                'product_name' => $group->productName(),
                'accounts' => array_map(
                    fn (CredentialDeliveryAccount $account): array => $this->account($group, $account),
                    $group->accounts(),
                ),
                'delivery_note' => $group->defaultTutorial(),
                'usage_guide' => $group->usageGuide(),
            ];
        }, $delivery->groups());
    }

    /**
     * Render-ready account row. The channel adapter (Blade) only escapes and
     * prints: URL validation and access-method mapping stay here.
     *
     * @return array<string, mixed>
     */
    private function account(CredentialDeliveryProductGroup $group, CredentialDeliveryAccount $account): array
    {
        $tutorial = $account->tutorial() !== null && $account->tutorial() !== $group->defaultTutorial()
            ? $account->tutorial()
            : null;

        if ($account->isLegacy()) {
            return [
                'label' => $account->label(),
                'kind' => 'legacy',
                'text' => $account->text(),
                'parts' => $account->legacyParts(),
                'tutorial' => $tutorial,
                'access' => null,
                'email' => null,
                'password' => null,
            ];
        }

        return [
            'label' => $account->label(),
            'kind' => 'structured',
            'text' => null,
            'parts' => null,
            'email' => $account->email(),
            'password' => $account->password(),
            'tutorial' => $tutorial,
            'access' => $this->access($account),
        ];
    }

    /**
     * @return null|array{label: string, value: ?string, url: ?string, domain: ?string}
     */
    private function access(CredentialDeliveryAccount $account): ?array
    {
        $readerMailLink = $account->readerMailLink();
        if ($readerMailLink !== null) {
            return [
                'label' => 'Akses Token',
                'value' => $account->accessValue(),
                'url' => $this->httpsUrl($readerMailLink),
                'domain' => $this->httpsHost($readerMailLink),
            ];
        }

        return match ($account->accessMethod()?->value) {
            'otp_mail_link' => [
                'label' => 'OTP Mail',
                'value' => null,
                'url' => $this->httpsUrl($account->accessValue()),
                'domain' => $this->httpsHost($account->accessValue()),
            ],
            'auth_token' => [
                'label' => 'Auth Token',
                'value' => $account->accessValue(),
                'url' => null,
                'domain' => null,
            ],
            default => null,
        };
    }

    private function httpsUrl(?string $url): ?string
    {
        return $this->httpsHost($url) === null ? null : (string) $url;
    }

    /**
     * Only a credential-free HTTPS host is ever rendered as a destination, so
     * a malformed stored link cannot become an email link.
     */
    private function httpsHost(?string $url): ?string
    {
        if (! is_string($url) || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if ($scheme !== 'https'
            || ! is_string($host)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return strtolower($host);
    }

    /**
     * @return null|array{event: OrderNotificationEvent, attempts: int}
     */
    private function claim(int|string $eventId): ?array
    {
        return DB::transaction(function () use ($eventId): ?array {
            $event = OrderNotificationEvent::query()->whereKey($eventId)->lockForUpdate()->first();

            if ($event === null
                || $event->event_type !== GuestOrderNotificationEvent::CredentialsReady
                || $event->status !== OrderNotificationEvent::STATUS_PENDING) {
                // Sent, cancelled, failed or already-claimed work is a no-op so
                // repeated queue deliveries and reconciliation never duplicate.
                return null;
            }

            if ($event->next_attempt_at !== null && $event->next_attempt_at->isFuture()) {
                return null;
            }

            if ((int) $event->attempts >= $this->maxAttempts()) {
                $event->forceFill([
                    'status' => OrderNotificationEvent::STATUS_FAILED,
                    'failed_at' => now(),
                    'delivery_started_at' => null,
                    'next_attempt_at' => null,
                    'last_error' => self::ERROR_ATTEMPT_LIMIT,
                ])->save();

                return null;
            }

            $event->forceFill([
                'attempts' => (int) $event->attempts + 1,
                'delivery_started_at' => now(),
                'next_attempt_at' => now()->addSeconds($this->leaseSeconds()),
            ])->save();

            return ['event' => $event, 'attempts' => (int) $event->attempts];
        }, 5);
    }

    /**
     * @param  array{event: OrderNotificationEvent, attempts: int}  $claim
     */
    private function finish(array $claim, array $changes): void
    {
        OrderNotificationEvent::query()
            ->whereKey($claim['event']->getKey())
            ->where('status', OrderNotificationEvent::STATUS_PENDING)
            ->where('attempts', $claim['attempts'])
            ->update([...$changes, 'updated_at' => now()]);
    }

    /**
     * @param  array{event: OrderNotificationEvent, attempts: int}  $claim
     */
    private function defer(array $claim, string $errorCode): void
    {
        if ($claim['attempts'] >= $this->maxAttempts()) {
            $this->finish($claim, [
                'status' => OrderNotificationEvent::STATUS_FAILED,
                'failed_at' => now(),
                'delivery_started_at' => null,
                'next_attempt_at' => null,
                'last_error' => $errorCode,
            ]);

            return;
        }

        $this->finish($claim, [
            'status' => OrderNotificationEvent::STATUS_PENDING,
            'delivery_started_at' => null,
            'next_attempt_at' => now()->addSeconds($this->backoffSeconds($claim['attempts'])),
            'last_error' => $errorCode,
        ]);
    }

    /**
     * @param  array{event: OrderNotificationEvent, attempts: int}  $claim
     */
    private function failDeliveryAttempt(array $claim, Throwable $exception): void
    {
        $terminal = $claim['attempts'] >= $this->maxAttempts();

        $this->finish($claim, [
            'status' => $terminal
                ? OrderNotificationEvent::STATUS_FAILED
                : OrderNotificationEvent::STATUS_PENDING,
            'failed_at' => $terminal ? now() : null,
            'delivery_started_at' => null,
            'next_attempt_at' => $terminal ? null : now()->addSeconds($this->backoffSeconds($claim['attempts'])),
            'last_error' => self::ERROR_MAIL_DELIVERY_FAILED,
        ]);

        Log::warning('storefront.credential_mail_failed', [
            'event_id' => (int) $claim['event']->getKey(),
            'order_id' => (string) $claim['event']->order_id,
            'attempts' => $claim['attempts'],
            'terminal' => $terminal,
            'exception' => $exception::class,
        ]);

        if (! $terminal) {
            // The queue retry is a wakeup only: the row lease decides whether a
            // retry may send, so this can never produce a duplicate attempt.
            throw new OrderCredentialMailDeliveryException('Credential email delivery failed.');
        }
    }

    private function markUnsafeMailer(int|string $eventId): void
    {
        $updated = OrderNotificationEvent::query()
            ->whereKey($eventId)
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->where('status', OrderNotificationEvent::STATUS_PENDING)
            ->update([
                'status' => OrderNotificationEvent::STATUS_FAILED,
                'failed_at' => now(),
                'delivery_started_at' => null,
                'next_attempt_at' => null,
                'last_error' => self::ERROR_MAILER_UNSAFE,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            Log::error('storefront.credential_mail_unsafe_transport', [
                'event_id' => (int) $eventId,
                'error_code' => self::ERROR_MAILER_UNSAFE,
            ]);
        }
    }

    private function deferWithoutAttempt(int|string $eventId, string $errorCode, int $seconds): void
    {
        OrderNotificationEvent::query()
            ->whereKey($eventId)
            ->where('event_type', GuestOrderNotificationEvent::CredentialsReady->value)
            ->where('status', OrderNotificationEvent::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->update([
                'next_attempt_at' => now()->addSeconds($seconds),
                'delivery_started_at' => null,
                'last_error' => $errorCode,
                'updated_at' => now(),
            ]);
    }

    private function dispatch(int $eventId): bool
    {
        try {
            SendOrderCredentialMail::dispatch($eventId)
                ->onQueue('notifications')
                ->afterCommit();

            return true;
        } catch (Throwable $exception) {
            // A missing queue is recovered by the scheduled reconciliation run.
            Log::warning('storefront.credential_mail_dispatch_failed', [
                'event_id' => $eventId,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function resolveMailerState(string $name, array $seen = []): string
    {
        if (in_array($name, $seen, true)) {
            return 'unconfigured';
        }

        $config = config('mail.mailers.'.$name);
        if (! is_array($config)) {
            return 'unsafe';
        }

        $transport = strtolower((string) ($config['transport'] ?? $name));

        if ($transport === 'log') {
            // A logging transport would persist credential plaintext into log
            // storage, so delivery refuses it permanently instead of degrading.
            return 'unsafe';
        }

        if ($transport === 'array') {
            // The array transport never leaves the process: treat it as an
            // unconfigured rail rather than pretending a delivery happened.
            return 'unconfigured';
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $states = array_map(
                fn (string $child): string => $this->resolveMailerState($child, [...$seen, $name]),
                array_map('strval', $config['mailers'] ?? []),
            );

            if (in_array('ready', $states, true)) {
                return 'ready';
            }

            return $states !== [] && count(array_unique($states)) === 1 && $states[0] === 'unsafe'
                ? 'unsafe'
                : 'unconfigured';
        }

        if ($transport === 'smtp') {
            return filled($config['password'] ?? null) && filled($config['host'] ?? null)
                ? 'ready'
                : 'unconfigured';
        }

        return 'ready';
    }

    private function leaseSeconds(): int
    {
        return max(30, (int) config('services.storefront.credential_email.lease_seconds', 120));
    }

    private function mailerRetrySeconds(): int
    {
        return max(60, (int) config('services.storefront.credential_email.mailer_retry_seconds', 600));
    }

    private function backoffSeconds(int $attempt): int
    {
        return match (true) {
            $attempt <= 1 => 60,
            $attempt === 2 => 120,
            default => 300,
        };
    }

    private function logState(string $event, Order $order, int $attempts, ?string $detail): void
    {
        $context = [
            'order_id' => (string) $order->getKey(),
            'attempts' => $attempts,
        ];
        if ($detail !== null) {
            $context['detail'] = $detail;
        }

        Log::info($event, $context);
    }

    private function absoluteUrl(string $url): string
    {
        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }
}
