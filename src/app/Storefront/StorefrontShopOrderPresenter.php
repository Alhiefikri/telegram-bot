<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Telegram\TelegramQrCodeRenderer;
use Illuminate\Support\Facades\URL;
use Throwable;

class StorefrontShopOrderPresenter
{
    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly TelegramQrCodeRenderer $qrCodes,
        private readonly PakasirProviderAdapter $pakasir,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(Order $order): array
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'payments']);

        /** @var Payment|null $payment */
        $payment = $order->payments->sortByDesc('created_at')->first();
        $isPaid = $this->isPaid($order, $payment);
        $isPendingPayment = $payment?->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment;
        $payableAmountFromProvider = $payment?->provider_total_payment !== null;
        $payableAmount = $payment?->provider_total_payment ?? $payment?->amount;
        $redirectUrl = URL::signedRoute('storefront.shop.orders.show', $order);
        $brand = $this->storefront->brand();

        return [
            'order' => $order,
            'payment' => $payment,
            'storefront' => $this->storefront,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.shop.index'),
            'pageTitle' => 'Order '.$order->invoice_number.' | '.$brand,
            'pageDescription' => 'Status checkout QRIS untuk invoice '.$order->invoice_number.'.',
            'canonicalUrl' => $redirectUrl,
            'supportHref' => $this->storefront->supportHref(),
            'supportDestination' => $this->storefront->supportDestination(),
            'statusUrl' => URL::signedRoute('storefront.shop.orders.status', $order),
            'redirectUrl' => $redirectUrl,
            'isPaid' => $isPaid,
            'isPendingPayment' => $isPendingPayment,
            'payableAmount' => $payableAmount,
            'payableAmountFromProvider' => $payableAmountFromProvider,
            'qrisImageDataUri' => $this->qrisImageDataUri($payment),
            'hostedPaymentUrl' => $this->hostedPaymentUrl($payment),
            'accountGroups' => $this->accountGroups($order, $isPaid),
        ];
    }

    private function isPaid(Order $order, ?Payment $payment): bool
    {
        return $payment?->status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
    }

    private function qrisImageDataUri(?Payment $payment): ?string
    {
        if ($payment?->status !== PaymentStatus::Pending || ! filled($payment->provider_payment_number)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($this->qrCodes->png($payment->provider_payment_number));
    }

    private function hostedPaymentUrl(?Payment $payment): ?string
    {
        if ($payment?->status !== PaymentStatus::Pending || strtolower((string) $payment->provider) !== 'pakasir') {
            return null;
        }

        try {
            return $this->pakasir->hostedPaymentUrl($payment);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return list<array{product_name: string, secrets: list<string>}>
     */
    private function accountGroups(Order $order, bool $isPaid): array
    {
        if (! $isPaid) {
            return [];
        }

        return $order->orderItems
            ->map(fn (OrderItem $item): array => [
                'product_name' => (string) $item->product_name,
                'secrets' => $item->stockItems
                    ->map(fn ($stockItem): string => (string) $stockItem->secret)
                    ->filter(fn (string $secret): bool => trim($secret) !== '')
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group): bool => $group['secrets'] !== [])
            ->values()
            ->all();
    }
}
