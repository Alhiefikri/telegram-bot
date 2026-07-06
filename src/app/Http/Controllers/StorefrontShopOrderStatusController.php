<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Throwable;

class StorefrontShopOrderStatusController extends Controller
{
    public function __invoke(Order $order, PakasirProviderAdapter $pakasir, PakasirCallbackProcessor $processor): JsonResponse
    {
        /** @var Payment|null $payment */
        $payment = $order->payments()
            ->latest('created_at')
            ->first();

        if ($payment === null || strtolower((string) $payment->provider) !== 'pakasir') {
            return response()->json([
                'ok' => false,
                'message' => 'Payment Pakasir tidak ditemukan.',
            ], 422);
        }

        if ($payment->status === PaymentStatus::Pending && $order->order_status === OrderStatus::PendingPayment) {
            try {
                $processor->processReconciliationForPayment($payment, $pakasir->transactionDetail($payment));
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'ok' => false,
                    'message' => 'Status pembayaran belum bisa dicek otomatis sekarang.',
                ]);
            }
        }

        $order->refresh();
        $payment->refresh();

        return response()->json([
            'ok' => true,
            'payment_status' => $payment->status?->value,
            'order_status' => $order->order_status?->value,
            'is_paid' => $this->isPaid($order, $payment),
            'redirect_url' => URL::signedRoute('storefront.shop.orders.show', $order),
        ]);
    }

    private function isPaid(Order $order, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
    }
}
