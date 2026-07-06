<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use Illuminate\Http\JsonResponse;
use Throwable;

class StorefrontShopOrderStatusController extends Controller
{
    public function __invoke(Order $order, PakasirProviderAdapter $pakasir, PakasirCallbackProcessor $processor): JsonResponse
    {
        $payment = $order->payments()
            ->where('provider', 'pakasir')
            ->latest('created_at')
            ->first();

        if ($payment !== null && $payment->status === PaymentStatus::Pending) {
            try {
                $processor->processReconciliationForPayment($payment, $pakasir->transactionDetail($payment));
            } catch (Throwable $exception) {
                report($exception);

                $order->refresh();
                $payment->refresh();

                return response()->json($this->payload($order, $payment) + [
                    'ok' => false,
                    'message' => 'Status pembayaran belum bisa diperbarui.',
                ], 202);
            }
        }

        $order->refresh();
        $payment?->refresh();

        return response()->json($this->payload($order, $payment) + ['ok' => true]);
    }

    /**
     * @return array<string, string|null>
     */
    private function payload(Order $order, mixed $payment): array
    {
        return [
            'order_id' => (string) $order->getKey(),
            'invoice_number' => $order->invoice_number,
            'order_status' => $order->order_status?->value,
            'payment_status' => $order->payment_status?->value,
            'provider_payment_status' => $payment?->status?->value,
        ];
    }
}
