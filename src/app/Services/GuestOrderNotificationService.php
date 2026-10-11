<?php

namespace App\Services;

use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\GuestOrderNotificationDeliveryException;
use App\Jobs\SendGuestOrderNotification;
use App\Mail\GuestOrderMilestoneMail;
use App\Models\Order;
use App\Models\OrderNotificationEvent;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class GuestOrderNotificationService
{
    public function __construct(private readonly StorefrontInvoiceUrl $invoiceUrls) {}

    public function capture(Order $order, ?string $email): void
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return;
        }

        DB::transaction(function () use ($order, $email): void {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedOrder->notification_email === $email || ! $this->acceptsRecipientChange($lockedOrder)) {
                return;
            }

            $lockedOrder->forceFill(['notification_email' => $email])->save();

            $this->record($lockedOrder, GuestOrderNotificationEvent::OrderCreated);
        }, 5);
    }

    /**
     * Only an unpaid checkout may set the notification address. Once the order
     * leaves PendingPayment the address is frozen for good: a replayed checkout
     * session must never be able to route paid account data to another address,
     * and a paid order that was created without one stays without one.
     */
    private function acceptsRecipientChange(Order $order): bool
    {
        return $order->order_status === OrderStatus::PendingPayment
            && $order->payment_status === PaymentStatus::Pending;
    }

    public function record(Order $order, GuestOrderNotificationEvent $eventType): ?OrderNotificationEvent
    {
        if (! $this->ownsEventType($eventType)) {
            $this->skipUnownedEventType('record', $order->getKey(), $eventType);

            return null;
        }

        if (! filled($order->notification_email)) {
            return null;
        }

        $event = OrderNotificationEvent::query()->firstOrCreate(
            [
                'order_id' => $order->getKey(),
                'event_type' => $eventType->value,
            ],
            ['status' => OrderNotificationEvent::STATUS_PENDING],
        );

        if ($event->wasRecentlyCreated) {
            SendGuestOrderNotification::dispatch($event->getKey())
                ->onQueue('notifications')
                ->afterCommit();
        }

        return $event;
    }

    public function deliver(int|string $eventId): void
    {
        $event = $this->claim($eventId);
        if ($event === null) {
            return;
        }

        $order = $event->order()->with('orderItems')->firstOrFail();
        $email = $order->notification_email;

        if (! filled($email)) {
            $this->markFailed($event, 'recipient_missing');
            throw new GuestOrderNotificationDeliveryException('Guest order notification delivery failed.');
        }

        try {
            Mail::to($email)->send($this->mailFor($event, $order));
        } catch (Throwable $exception) {
            $this->markFailed($event, 'mail_delivery_failed');
            Log::warning('storefront.guest_order_notification_failed', [
                'event_id' => $event->getKey(),
                'order_id' => $order->getKey(),
                'event_type' => $event->event_type?->value,
                'exception' => $exception::class,
            ]);

            // Do not rethrow the transport exception: provider messages can
            // contain recipient addresses or other data that must not reach
            // failed-job payloads or application logs.
            throw new GuestOrderNotificationDeliveryException('Guest order notification delivery failed.');
        }

        OrderNotificationEvent::query()
            ->whereKey($event->getKey())
            ->where('status', OrderNotificationEvent::STATUS_SENDING)
            ->update([
                'status' => OrderNotificationEvent::STATUS_SENT,
                'sent_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    public function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Credential delivery owns its own retry/lease semantics, so the milestone
     * mailer never creates or claims that event type. A delivery attempt for a
     * row it does not own is skipped instead of failing the queued job.
     */
    private function ownsEventType(?GuestOrderNotificationEvent $eventType): bool
    {
        return $eventType !== GuestOrderNotificationEvent::CredentialsReady;
    }

    private function skipUnownedEventType(string $stage, int|string|null $eventId = null, ?GuestOrderNotificationEvent $eventType = null): void
    {
        Log::warning('storefront.guest_notification_unowned_event_type', [
            'stage' => $stage,
            'event_id' => $eventId === null ? null : (string) $eventId,
            'event_type' => $eventType?->value,
        ]);
    }

    private function claim(int|string $eventId): ?OrderNotificationEvent
    {
        return DB::transaction(function () use ($eventId): ?OrderNotificationEvent {
            $event = OrderNotificationEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();

            if (! $this->ownsEventType($event->event_type)) {
                $this->skipUnownedEventType('claim', $event->getKey(), $event->event_type);

                return null;
            }

            if ($event->status === OrderNotificationEvent::STATUS_SENT
                || $event->status === OrderNotificationEvent::STATUS_SENDING) {
                return null;
            }

            if ($event->status === OrderNotificationEvent::STATUS_FAILED) {
                throw new GuestOrderNotificationDeliveryException('Guest order notification delivery failed.');
            }

            $event->forceFill([
                'status' => OrderNotificationEvent::STATUS_SENDING,
                'delivery_started_at' => now(),
                'attempts' => (int) $event->attempts + 1,
                'last_error' => null,
            ])->save();

            return $event;
        }, 5);
    }

    private function markFailed(OrderNotificationEvent $event, string $error): void
    {
        OrderNotificationEvent::query()
            ->whereKey($event->getKey())
            ->where('status', OrderNotificationEvent::STATUS_SENDING)
            ->update([
                'status' => OrderNotificationEvent::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => $error,
                'updated_at' => now(),
            ]);
    }

    private function mailFor(OrderNotificationEvent $event, Order $order): GuestOrderMilestoneMail
    {
        $eventType = $event->event_type;
        $orderStatus = $order->order_status instanceof OrderStatus
            ? $order->order_status
            : OrderStatus::tryFrom((string) $order->order_status) ?? OrderStatus::PendingPayment;

        $paymentDeadline = null;
        if ($eventType === GuestOrderNotificationEvent::OrderCreated
            && $orderStatus === OrderStatus::PendingPayment
            && $order->expires_at !== null) {
            $paymentDeadline = $order->expires_at
                ->copy()
                ->timezone((string) config('app.timezone'))
                ->format('Y-m-d H:i T');
        }

        $items = $order->orderItems
            ->map(static fn ($item): array => [
                'name' => mb_substr(trim((string) $item->product_name), 0, 160),
                'quantity' => (int) $item->quantity,
            ])
            ->values()
            ->all();
        $invoiceUrl = $this->absoluteUrl($this->invoiceUrls->show($order));

        return new GuestOrderMilestoneMail(
            eventType: $eventType,
            invoiceNumber: (string) $order->invoice_number,
            orderStatus: $orderStatus->value,
            statusLabel: $this->statusLabel($orderStatus),
            items: $items,
            paymentDeadline: $paymentDeadline,
            invoiceUrl: $invoiceUrl,
            orderAccessUrl: $this->absoluteUrl(route('storefront.order-access.create')),
        );
    }

    private function statusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'Draft',
            OrderStatus::PendingPayment => 'Menunggu pembayaran',
            OrderStatus::Processing => 'Sedang diproses',
            OrderStatus::Completed => 'Selesai',
            OrderStatus::Cancelled => 'Dibatalkan',
            OrderStatus::Expired => 'Kedaluwarsa',
        };
    }

    private function absoluteUrl(string $url): string
    {
        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }
}
