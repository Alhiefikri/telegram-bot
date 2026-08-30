<?php

namespace App\Services;

use App\Exceptions\ProductWaitlistDeliveryException;
use App\Jobs\SendProductWaitlistConfirmation;
use App\Mail\ProductWaitlistConfirmationMail;
use App\Models\Product;
use App\Models\ProductWaitlistNotification;
use App\Models\ProductWaitlistSubscription;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

final class ProductWaitlistSubscriptionService
{
    public const RESULT_SUCCESS = 'success';

    public const RESULT_ALREADY_REQUESTED = 'already_requested';

    public const RESULT_UNSUBSCRIBED = 'unsubscribed';

    public const RESULT_EXPIRED_LINK = 'expired_link';

    public function __construct(private readonly ProductWaitlistEmailHasher $emails) {}

    /**
     * Create or retain one pending subscription without exposing dedupe state.
     *
     * @return bool true when a confirmation dispatch was created
     */
    public function subscribe(Product $product, ?string $email): bool
    {
        $normalizedEmail = $this->emails->normalize($email);
        if ($normalizedEmail === null) {
            return false;
        }

        $emailHash = $this->emails->hash($normalizedEmail);
        $pending = DB::transaction(function () use ($product, $emailHash, $normalizedEmail): ?array {
            /** @var Product|null $lockedProduct */
            $lockedProduct = Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->first();
            if ($lockedProduct === null
                || ! $lockedProduct->isPublicVisible()
                || $lockedProduct->availableStock() > 0) {
                return null;
            }

            $subscription = ProductWaitlistSubscription::query()
                ->where('product_id', $lockedProduct->getKey())
                ->where('email_hash', $emailHash)
                ->lockForUpdate()
                ->first();
            $expiresAt = now()->addMinutes(max(1, (int) config('services.storefront.waitlist_confirmation_ttl_minutes', 60)));

            if ($subscription === null) {
                $subscription = ProductWaitlistSubscription::query()->create([
                    'product_id' => $lockedProduct->getKey(),
                    'email' => $normalizedEmail,
                    'email_hash' => $emailHash,
                    'status' => ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION,
                    'confirmation_expires_at' => $expiresAt,
                ]);

                return [
                    'id' => $subscription->getKey(),
                    'expires_at' => $expiresAt,
                ];
            }

            if (in_array($subscription->status, [
                ProductWaitlistSubscription::STATUS_ACTIVE,
                ProductWaitlistSubscription::STATUS_NOTIFIED,
                ProductWaitlistSubscription::STATUS_UNSUBSCRIBED,
            ], true)) {
                return null;
            }

            if ($subscription->confirmation_expires_at !== null
                && $subscription->confirmation_expires_at->isFuture()) {
                return null;
            }

            $subscription->forceFill([
                'email' => $normalizedEmail,
                'confirmation_expires_at' => $expiresAt,
                'confirmation_sent_at' => null,
                'confirmation_delivery_started_at' => null,
                'confirmation_last_error' => null,
            ])->save();

            return [
                'id' => $subscription->getKey(),
                'expires_at' => $expiresAt,
            ];
        }, 5);

        if ($pending === null) {
            return false;
        }

        SendProductWaitlistConfirmation::dispatch($pending['id'])
            ->onQueue('notifications')
            ->afterCommit();

        return true;
    }

    public function sendConfirmation(int|string $subscriptionId, ?string $confirmationUrl = null): void
    {
        $claim = DB::transaction(function () use ($subscriptionId): ?array {
            /** @var ProductWaitlistSubscription|null $subscription */
            $subscription = ProductWaitlistSubscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();
            if ($subscription === null
                || $subscription->status !== ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION
                || $subscription->confirmation_sent_at !== null
                || $subscription->confirmation_expires_at === null
                || $subscription->confirmation_expires_at->isPast()) {
                return null;
            }

            if ($subscription->confirmation_delivery_started_at !== null
                && $subscription->confirmation_delivery_started_at->gt(now()->subMinutes(5))) {
                return null;
            }

            $subscription->forceFill([
                'confirmation_delivery_started_at' => now(),
                'confirmation_attempts' => (int) $subscription->confirmation_attempts + 1,
                'confirmation_last_error' => null,
            ])->save();

            return [
                'id' => $subscription->getKey(),
                'product_id' => $subscription->product_id,
                'email' => $subscription->email,
                'expires_at' => $subscription->confirmation_expires_at,
            ];
        }, 5);

        if ($claim === null) {
            return;
        }

        $product = Product::withTrashed()->whereKey($claim['product_id'])->first();
        if ($product === null) {
            $this->finishConfirmationFailure($claim['id'], 'product_missing');

            return;
        }

        $confirmationUrl ??= $this->confirmationUrl($claim['id'], $claim['expires_at']);

        try {
            Mail::to($claim['email'])->send(new ProductWaitlistConfirmationMail(
                productName: $this->safeProductName($product),
                confirmationUrl: $confirmationUrl,
            ));
        } catch (Throwable $exception) {
            $this->finishConfirmationFailure($claim['id'], 'mail_delivery_failed');
            Log::warning('storefront.product_waitlist_confirmation_failed', [
                'subscription_id' => $claim['id'],
                'product_id' => $claim['product_id'],
                'exception' => $exception::class,
            ]);

            throw new ProductWaitlistDeliveryException('Product waitlist delivery failed.');
        }

        ProductWaitlistSubscription::query()
            ->whereKey($claim['id'])
            ->whereNotNull('confirmation_delivery_started_at')
            ->update([
                'confirmation_sent_at' => now(),
                'confirmation_delivery_started_at' => null,
                'confirmation_last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{status:string, productId:?int}
     */
    public function confirm(Request $request, int|string $subscriptionId): array
    {
        if (! URL::hasValidSignature($request)) {
            return ['status' => self::RESULT_EXPIRED_LINK, 'productId' => null];
        }

        $result = DB::transaction(function () use ($subscriptionId): array {
            /** @var ProductWaitlistSubscription|null $subscription */
            $subscription = ProductWaitlistSubscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();
            if ($subscription === null) {
                return ['status' => self::RESULT_EXPIRED_LINK, 'productId' => null];
            }

            if ($subscription->status === ProductWaitlistSubscription::STATUS_UNSUBSCRIBED) {
                return ['status' => self::RESULT_UNSUBSCRIBED, 'productId' => (int) $subscription->product_id];
            }

            if ($subscription->status !== ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION) {
                return ['status' => self::RESULT_ALREADY_REQUESTED, 'productId' => (int) $subscription->product_id];
            }

            if ($subscription->confirmation_expires_at === null
                || $subscription->confirmation_expires_at->isPast()) {
                return ['status' => self::RESULT_EXPIRED_LINK, 'productId' => (int) $subscription->product_id];
            }

            $subscription->forceFill([
                'status' => ProductWaitlistSubscription::STATUS_ACTIVE,
                'confirmed_at' => now(),
                'confirmation_expires_at' => null,
                'confirmation_delivery_started_at' => null,
            ])->save();

            return ['status' => self::RESULT_SUCCESS, 'productId' => (int) $subscription->product_id];
        }, 5);

        return $result;
    }

    /**
     * @return array{status:string, productId:?int}
     */
    public function unsubscribe(Request $request, int|string $subscriptionId): array
    {
        if (! URL::hasValidSignature($request)) {
            return ['status' => self::RESULT_EXPIRED_LINK, 'productId' => null];
        }

        return DB::transaction(function () use ($subscriptionId): array {
            $pendingNotificationIds = ProductWaitlistNotification::query()
                ->where('subscription_id', $subscriptionId)
                ->whereIn('status', [
                    ProductWaitlistNotification::STATUS_PENDING,
                    ProductWaitlistNotification::STATUS_FAILED,
                ])
                ->lockForUpdate()
                ->pluck('id');
            /** @var ProductWaitlistSubscription|null $subscription */
            $subscription = ProductWaitlistSubscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();
            if ($subscription === null) {
                return ['status' => self::RESULT_EXPIRED_LINK, 'productId' => null];
            }

            if ($subscription->status !== ProductWaitlistSubscription::STATUS_UNSUBSCRIBED) {
                $subscription->forceFill([
                    'status' => ProductWaitlistSubscription::STATUS_UNSUBSCRIBED,
                    'unsubscribed_at' => now(),
                    'confirmation_delivery_started_at' => null,
                ])->save();

                if ($pendingNotificationIds->isNotEmpty()) {
                    ProductWaitlistNotification::query()
                        ->whereKey($pendingNotificationIds)
                        ->update([
                            'status' => ProductWaitlistNotification::STATUS_SKIPPED,
                            'next_attempt_at' => null,
                            'updated_at' => now(),
                        ]);
                }
            }

            return ['status' => self::RESULT_UNSUBSCRIBED, 'productId' => (int) $subscription->product_id];
        }, 5);
    }

    public function reconcileConfirmations(int $chunk = 100): int
    {
        $subscriptions = ProductWaitlistSubscription::query()
            ->where('status', ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION)
            ->whereNull('confirmation_sent_at')
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '>', now())
            ->where(function ($query): void {
                $query
                    ->whereNull('confirmation_delivery_started_at')
                    ->orWhere('confirmation_delivery_started_at', '<=', now()->subMinutes(5));
            })
            ->orderBy('id')
            ->limit(max(1, $chunk))
            ->get(['id', 'confirmation_expires_at']);

        foreach ($subscriptions as $subscription) {
            if ($subscription->confirmation_expires_at === null) {
                continue;
            }
            SendProductWaitlistConfirmation::dispatch($subscription->getKey())
                ->onQueue('notifications');
        }

        return $subscriptions->count();
    }

    public function confirmationUrl(int|string $subscriptionId, DateTimeInterface $expiresAt): string
    {
        return URL::temporarySignedRoute(
            'storefront.waitlist.confirm',
            $expiresAt,
            ['subscription' => $subscriptionId],
        );
    }

    public function unsubscribeUrl(int|string $subscriptionId): string
    {
        return URL::temporarySignedRoute(
            'storefront.waitlist.unsubscribe',
            now()->addDays(max(1, (int) config('services.storefront.waitlist_unsubscribe_ttl_days', 30))),
            ['subscription' => $subscriptionId],
        );
    }

    private function finishConfirmationFailure(int|string $subscriptionId, string $error): void
    {
        ProductWaitlistSubscription::query()
            ->whereKey($subscriptionId)
            ->whereNotNull('confirmation_delivery_started_at')
            ->update([
                'confirmation_delivery_started_at' => null,
                'confirmation_last_error' => $error,
                'updated_at' => now(),
            ]);
    }

    private function safeProductName(Product $product): string
    {
        $name = preg_replace('/[\r\n]+/', ' ', trim((string) $product->name)) ?? '';

        return mb_substr($name !== '' ? $name : 'Produk', 0, 160);
    }
}
