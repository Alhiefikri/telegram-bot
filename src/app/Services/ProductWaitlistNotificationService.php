<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Exceptions\ProductWaitlistDeliveryException;
use App\Jobs\MaterializeProductWaitlist;
use App\Jobs\SendProductWaitlistNotification;
use App\Mail\ProductWaitlistRestockedMail;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductWaitlistEvent;
use App\Models\ProductWaitlistNotification;
use App\Models\ProductWaitlistSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class ProductWaitlistNotificationService
{
    public const QUEUE = 'notifications';

    public const MAX_ATTEMPTS = 3;

    private const CLAIM_LEASE_MINUTES = 5;

    private const RETRY_DELAY_SECONDS = 60;

    public function __construct(private readonly ProductWaitlistSubscriptionService $subscriptions) {}

    public function scheduleForMovement(int|string $movementId): void
    {
        $movement = InventoryMovement::query()->whereKey($movementId)->first();
        if ($movement === null || ! $this->isRestockMovement($movement)) {
            return;
        }

        ProductWaitlistEvent::query()->firstOrCreate(
            ['inventory_movement_id' => $movement->getKey()],
            [
                'product_id' => $movement->product_id,
                'available_stock' => (int) $movement->available_after,
                'status' => ProductWaitlistEvent::STATUS_PENDING,
            ],
        );

        MaterializeProductWaitlist::dispatch($movementId)
            ->onQueue(self::QUEUE)
            ->afterCommit();
    }

    /**
     * Materialize a bounded, immutable recipient slice for one stock event.
     *
     * @return array{event_id:?int, notification_ids:list<int>, has_more:bool}
     */
    public function materialize(int|string $movementId): array
    {
        $batchSize = max(1, (int) config('services.storefront.waitlist_max_recipients_per_event', 500));
        $result = DB::transaction(function () use ($movementId, $batchSize): array {
            /** @var InventoryMovement|null $movement */
            $movement = InventoryMovement::query()->whereKey($movementId)->lockForUpdate()->first();
            if ($movement === null || ! $this->isRestockMovement($movement)) {
                return ['event_id' => null, 'notification_ids' => [], 'has_more' => false];
            }

            /** @var ProductWaitlistEvent $event */
            $event = ProductWaitlistEvent::query()->firstOrCreate(
                ['inventory_movement_id' => $movement->getKey()],
                [
                    'product_id' => $movement->product_id,
                    'available_stock' => (int) $movement->available_after,
                    'status' => ProductWaitlistEvent::STATUS_PENDING,
                ],
            );

            if ($event->materialized_at !== null || $event->status !== ProductWaitlistEvent::STATUS_PENDING) {
                return [
                    'event_id' => (int) $event->getKey(),
                    'notification_ids' => [],
                    'has_more' => false,
                ];
            }

            /** @var Product|null $product */
            $product = Product::withTrashed()->whereKey($movement->product_id)->lockForUpdate()->first();
            if (! $this->isPubliclyAvailable($product)) {
                $now = now();
                $event->forceFill([
                    'status' => ProductWaitlistEvent::STATUS_SKIPPED,
                    'materialized_at' => $now,
                    'skipped_at' => $now,
                ])->save();

                return [
                    'event_id' => (int) $event->getKey(),
                    'notification_ids' => [],
                    'has_more' => false,
                ];
            }

            $cursor = (int) ($event->materialization_cursor ?? 0);
            $subscriptions = ProductWaitlistSubscription::query()
                ->where('product_id', $movement->product_id)
                ->where('status', ProductWaitlistSubscription::STATUS_ACTIVE)
                ->whereDoesntHave('notifications', function (Builder $notifications): void {
                    $notifications->whereIn('status', [
                        ProductWaitlistNotification::STATUS_PENDING,
                        ProductWaitlistNotification::STATUS_SENDING,
                        ProductWaitlistNotification::STATUS_FAILED,
                    ]);
                })
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit($batchSize + 1)
                ->get(['id']);
            $batch = $subscriptions->take($batchSize);
            $notificationIds = [];

            if ($batch->isNotEmpty()) {
                $now = now();
                ProductWaitlistNotification::query()->insertOrIgnore(
                    $batch->map(fn (ProductWaitlistSubscription $subscription): array => [
                        'product_waitlist_event_id' => $event->getKey(),
                        'subscription_id' => $subscription->getKey(),
                        'status' => ProductWaitlistNotification::STATUS_PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
                $notificationIds = ProductWaitlistNotification::query()
                    ->where('product_waitlist_event_id', $event->getKey())
                    ->whereIn('subscription_id', $batch->pluck('id'))
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn (int|string $id): int => (int) $id)
                    ->all();
            }

            $hasMore = $subscriptions->count() > $batchSize;
            $event->forceFill([
                'materialization_cursor' => $batch->last()?->getKey() ?? $event->materialization_cursor,
                'available_stock' => (int) $movement->available_after,
                'status' => $hasMore ? ProductWaitlistEvent::STATUS_PENDING : ProductWaitlistEvent::STATUS_COMPLETE,
                'materialized_at' => $hasMore ? null : now(),
            ])->save();

            return [
                'event_id' => (int) $event->getKey(),
                'notification_ids' => $notificationIds,
                'has_more' => $hasMore,
            ];
        }, 5);

        foreach ($result['notification_ids'] as $notificationId) {
            SendProductWaitlistNotification::dispatch($notificationId)->onQueue(self::QUEUE)->afterCommit();
        }

        if ($result['has_more']) {
            MaterializeProductWaitlist::dispatch($movementId)
                ->onQueue(self::QUEUE)
                ->afterCommit();
        }

        return $result;
    }

    public function deliver(int|string $notificationId): void
    {
        $claim = DB::transaction(function () use ($notificationId): ?array {
            /** @var ProductWaitlistNotification|null $notification */
            $notification = ProductWaitlistNotification::query()->whereKey($notificationId)->lockForUpdate()->first();
            if ($notification === null
                || in_array($notification->status, [
                    ProductWaitlistNotification::STATUS_SENT,
                    ProductWaitlistNotification::STATUS_SKIPPED,
                ], true)
                || (int) $notification->attempts >= self::MAX_ATTEMPTS) {
                return null;
            }

            if ($notification->next_attempt_at !== null && $notification->next_attempt_at->isFuture()) {
                return null;
            }

            if ($notification->status === ProductWaitlistNotification::STATUS_SENDING
                && $notification->delivery_started_at !== null
                && $notification->delivery_started_at->gt(now()->subMinutes(self::CLAIM_LEASE_MINUTES))) {
                return null;
            }

            /** @var ProductWaitlistEvent|null $event */
            $event = ProductWaitlistEvent::query()->whereKey($notification->product_waitlist_event_id)->lockForUpdate()->first();
            /** @var ProductWaitlistSubscription|null $subscription */
            $subscription = ProductWaitlistSubscription::query()->whereKey($notification->subscription_id)->lockForUpdate()->first();
            /** @var Product|null $product */
            $product = $event === null
                ? null
                : Product::withTrashed()->whereKey($event->product_id)->lockForUpdate()->first();

            if ($event === null
                || $event->status === ProductWaitlistEvent::STATUS_SKIPPED
                || $subscription === null
                || ! $subscription->isActive()
                || ! $this->isPubliclyAvailable($product)) {
                $notification->forceFill([
                    'status' => ProductWaitlistNotification::STATUS_SKIPPED,
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ])->save();

                return null;
            }

            $notification->forceFill([
                'status' => ProductWaitlistNotification::STATUS_SENDING,
                'delivery_started_at' => now(),
                'attempts' => (int) $notification->attempts + 1,
                'failed_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
            ])->save();

            return [
                'notification_id' => $notification->getKey(),
                'event_id' => $event->getKey(),
                'subscription_id' => $subscription->getKey(),
                'product_id' => $product->getKey(),
            ];
        }, 5);

        if ($claim === null) {
            return;
        }

        $subscription = ProductWaitlistSubscription::query()->find($claim['subscription_id']);
        $product = Product::withTrashed()->whereKey($claim['product_id'])->first();
        if ($subscription === null || ! $subscription->isActive() || ! $this->isPubliclyAvailable($product)) {
            $this->markSkipped($claim['notification_id']);

            return;
        }

        try {
            $availableStock = $product->availableStock();
            Mail::to($subscription->email)->send(new ProductWaitlistRestockedMail(
                productName: $this->safeProductName($product),
                availableStock: $availableStock,
                productUrl: $this->absoluteUrl(route('storefront.products.show', ['product' => $product->getKey()])),
                unsubscribeUrl: $this->subscriptions->unsubscribeUrl($subscription->getKey()),
            ));
        } catch (Throwable $exception) {
            $this->markFailed($claim['notification_id']);
            Log::warning('storefront.product_waitlist_notification_failed', [
                'notification_id' => $claim['notification_id'],
                'subscription_id' => $claim['subscription_id'],
                'event_id' => $claim['event_id'],
                'exception' => $exception::class,
            ]);

            throw new ProductWaitlistDeliveryException('Product waitlist delivery failed.');
        }

        $this->markSent($claim['notification_id'], $claim['subscription_id']);
    }

    public function reconcile(int $chunk = 100): int
    {
        $limit = max(1, $chunk);
        $notificationIds = ProductWaitlistNotification::query()
            ->whereIn('status', [
                ProductWaitlistNotification::STATUS_PENDING,
                ProductWaitlistNotification::STATUS_FAILED,
            ])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $eventMovementIds = ProductWaitlistEvent::query()
            ->where('status', ProductWaitlistEvent::STATUS_PENDING)
            ->whereNull('materialized_at')
            ->orderBy('id')
            ->limit($limit)
            ->with('movement:id')
            ->get()
            ->pluck('movement.id')
            ->filter()
            ->map(static fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $confirmationCount = $this->subscriptions->reconcileConfirmations($limit);

        foreach ($notificationIds as $notificationId) {
            SendProductWaitlistNotification::dispatch($notificationId)->onQueue(self::QUEUE);
        }
        foreach ($eventMovementIds as $movementId) {
            MaterializeProductWaitlist::dispatch($movementId)->onQueue(self::QUEUE);
        }

        return $confirmationCount + count($notificationIds) + count($eventMovementIds);
    }

    private function markSent(int|string $notificationId, int|string $subscriptionId): void
    {
        DB::transaction(function () use ($notificationId, $subscriptionId): void {
            /** @var ProductWaitlistNotification|null $notification */
            $notification = ProductWaitlistNotification::query()->whereKey($notificationId)->lockForUpdate()->first();
            if ($notification === null || $notification->status !== ProductWaitlistNotification::STATUS_SENDING) {
                return;
            }

            $notification->forceFill([
                'status' => ProductWaitlistNotification::STATUS_SENT,
                'sent_at' => now(),
                'delivery_started_at' => null,
                'failed_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
            ])->save();

            /** @var ProductWaitlistSubscription|null $subscription */
            $subscription = ProductWaitlistSubscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();
            if ($subscription?->status === ProductWaitlistSubscription::STATUS_ACTIVE) {
                $subscription->forceFill([
                    'status' => ProductWaitlistSubscription::STATUS_NOTIFIED,
                    'notified_at' => now(),
                ])->save();
            }
        }, 5);
    }

    private function markFailed(int|string $notificationId): void
    {
        DB::transaction(function () use ($notificationId): void {
            /** @var ProductWaitlistNotification|null $notification */
            $notification = ProductWaitlistNotification::query()->whereKey($notificationId)->lockForUpdate()->first();
            if ($notification === null || $notification->status !== ProductWaitlistNotification::STATUS_SENDING) {
                return;
            }

            $attempts = (int) $notification->attempts;
            $notification->forceFill([
                'status' => ProductWaitlistNotification::STATUS_FAILED,
                'failed_at' => now(),
                'delivery_started_at' => null,
                'next_attempt_at' => $attempts < self::MAX_ATTEMPTS
                    ? now()->addSeconds(self::RETRY_DELAY_SECONDS)
                    : null,
                'last_error' => 'mail_delivery_failed',
            ])->save();
        }, 5);
    }

    private function markSkipped(int|string $notificationId): void
    {
        ProductWaitlistNotification::query()
            ->whereKey($notificationId)
            ->where('status', '!=', ProductWaitlistNotification::STATUS_SENT)
            ->update([
                'status' => ProductWaitlistNotification::STATUS_SKIPPED,
                'delivery_started_at' => null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function isRestockMovement(InventoryMovement $movement): bool
    {
        return ($movement->type === InventoryMovementType::Adjustment
                || ($movement->type === InventoryMovementType::Availability && (int) $movement->available_before === 0))
            && (int) $movement->quantity > 0
            && (int) $movement->available_after > 0;
    }

    private function isPubliclyAvailable(?Product $product): bool
    {
        return $product !== null
            && $product->isPublicVisible()
            && $product->availableStock() > 0;
    }

    private function safeProductName(Product $product): string
    {
        $name = preg_replace('/[\r\n]+/', ' ', trim((string) $product->name)) ?? '';

        return mb_substr($name !== '' ? $name : 'Produk', 0, 160);
    }

    private function absoluteUrl(string $url): string
    {
        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }
}
