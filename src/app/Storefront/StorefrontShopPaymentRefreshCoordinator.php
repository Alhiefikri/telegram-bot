<?php

namespace App\Storefront;

use App\Models\Payment;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class StorefrontShopPaymentRefreshCoordinator
{
    public function recentlyRefreshed(Payment $payment): bool
    {
        return Cache::has($this->recentKey($payment));
    }

    public function acquire(Payment $payment): ?Lock
    {
        $lock = Cache::lock($this->lockKey($payment), $this->leaseSeconds());

        return $lock->get() ? $lock : null;
    }

    public function markRecentlyRefreshed(Payment $payment): void
    {
        Cache::put($this->recentKey($payment), true, $this->windowSeconds());
    }

    public function retryAfterSeconds(): int
    {
        return max(1, (int) config('services.pakasir.status_refresh_in_progress_retry_after_seconds', 3));
    }

    public function lockKey(Payment $payment): string
    {
        return 'pakasir:status-refresh:single-flight:payment:'.$this->paymentIdentity($payment);
    }

    private function recentKey(Payment $payment): string
    {
        return 'pakasir:status-refresh:recent:payment:'.$this->paymentIdentity($payment);
    }

    private function leaseSeconds(): int
    {
        return max(
            1,
            (int) config('services.pakasir.status_refresh_lock_lease_seconds', 15),
            ProviderVerificationProfiles::requiredLeaseSeconds(),
        );
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('services.pakasir.status_refresh_single_flight_window_seconds', 5));
    }

    private function paymentIdentity(Payment $payment): string
    {
        return (string) ($payment->getKey() ?: hash('sha256', (string) $payment->merchant_ref));
    }
}
