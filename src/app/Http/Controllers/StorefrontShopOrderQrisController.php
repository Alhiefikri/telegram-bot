<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisQrisImage;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Telegram\TelegramQrCodeRenderer;
use App\Support\PaymentProvider;
use Illuminate\Http\Response;
use InvalidArgumentException;

class StorefrontShopOrderQrisController extends Controller
{
    public function __invoke(
        Order $order,
        KlikQrisNativeQrisService $klikQris,
        KlikQrisQrisImage $images,
        SakurupiahInvoiceLifecycleService $sakurupiah,
        TelegramQrCodeRenderer $qrCodes,
    ): Response {
        $payment = $order->payments()->latest('created_at')->first();

        abort_unless(
            $order->order_status === OrderStatus::PendingPayment
                && $payment?->status === PaymentStatus::Pending,
            404,
        );

        if (strtolower((string) $payment?->provider) === PaymentProvider::KLIKQRIS) {
            $payable = $klikQris->classify($payment)->payable();
            abort_unless($payable !== null, 404);

            try {
                $contents = $images->contents($payable);
            } catch (InvalidArgumentException) {
                $recoveredPayable = $klikQris->recoverPresentationImage($payment);
                if ($recoveredPayable === null) {
                    abort(404);
                }

                $contents = $images->contents($recoveredPayable, preferFallback: true);
            }

            return $this->pngResponse($contents);
        }

        if (strtolower((string) $payment?->provider) === PaymentProvider::SAKURUPIAH) {
            $payable = $sakurupiah->classify($payment)->payable();
            abort_unless($payable?->isDirectQris() === true, 404);

            try {
                return $this->pngResponse($qrCodes->png((string) $payable->qrisPayload()));
            } catch (InvalidArgumentException) {
                abort(404);
            }
        }

        abort(404);
    }

    private function pngResponse(string $contents): Response
    {
        return response($contents)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
