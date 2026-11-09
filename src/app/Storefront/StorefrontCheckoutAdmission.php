<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StorefrontCheckoutAdmission
{
    public const BROWSER_PENDING_LIMIT = 2;

    public const IP_PENDING_LIMIT = 20;

    private readonly string $browserHash;

    private readonly ?string $ipHash;

    public function __construct(string $browserKey, ?string $visitorIp = null)
    {
        $this->browserHash = $this->digest('browser', $browserKey);
        $packed = is_string($visitorIp) && filter_var($visitorIp, FILTER_VALIDATE_IP) !== false
            ? inet_pton($visitorIp)
            : false;
        $this->ipHash = $packed === false ? null : $this->digest('ip', bin2hex($packed));
    }

    public function existing(string $checkoutKey): ?Order
    {
        $orderId = DB::table('storefront_checkout_admissions')
            ->where('browser_hash', $this->browserHash)
            ->where('checkout_key', $checkoutKey)
            ->value('order_id');

        return $orderId === null ? null : Order::query()->findOrFail($orderId);
    }

    /** Called before Customer/Cart locks, inside the Order creation transaction. */
    public function guard(string $checkoutKey): ?Order
    {
        $this->requireTransaction();
        $subjects = array_values(array_filter([$this->browserHash, $this->ipHash]));
        sort($subjects, SORT_STRING);
        foreach ($subjects as $subject) {
            DB::table('storefront_checkout_subjects')->insertOrIgnore(['subject_hash' => $subject]);
            DB::table('storefront_checkout_subjects')->where('subject_hash', $subject)->lockForUpdate()->first();
        }

        if (($existing = $this->existing($checkoutKey)) !== null) {
            return $existing;
        }

        $this->assertCapacity();

        return null;
    }

    public function assertCapacity(): void
    {
        $browserOrders = $this->pending()->where('browser_hash', $this->browserHash);
        $browserFull = (clone $browserOrders)->count() >= self::BROWSER_PENDING_LIMIT;
        $ipFull = $this->ipHash !== null
            && $this->pending()->where('ip_hash', $this->ipHash)->count() >= self::IP_PENDING_LIMIT;
        if (! $browserFull && ! $ipFull) {
            return;
        }

        // Never disclose another browser's invoice, even on the same network.
        $urls = (clone $browserOrders)->orderBy('orders.id')->limit(self::BROWSER_PENDING_LIMIT)
            ->pluck('orders.id')->map(fn ($id): string => app(StorefrontInvoiceUrl::class)->show(Order::query()->findOrFail($id)))
            ->all();
        throw new StorefrontCheckoutLimitReached($urls, sharedNetwork: ! $browserFull);
    }

    public function record(Order $order, string $checkoutKey): void
    {
        $this->requireTransaction();
        DB::table('storefront_checkout_admissions')->insert([
            'browser_hash' => $this->browserHash,
            'ip_hash' => $this->ipHash,
            'checkout_key' => $checkoutKey,
            'order_id' => $order->getKey(),
            'created_at' => now(),
        ]);
    }

    private function pending(): Builder
    {
        return DB::table('storefront_checkout_admissions')
            ->join('orders', 'orders.id', '=', 'storefront_checkout_admissions.order_id')
            ->where('orders.order_status', OrderStatus::PendingPayment->value)
            ->where('orders.payment_status', PaymentStatus::Pending->value);
    }

    private function digest(string $kind, string $value): string
    {
        return hash_hmac('sha256', 'storefront-admission:v1:'.$kind.':'.$value, (string) config('app.key'));
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Checkout admission requires the Order transaction.');
        }
    }
}
