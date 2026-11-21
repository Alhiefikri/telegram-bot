<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\TelegramStars\TelegramStarsOperations;
use App\Services\TelegramStars\TelegramStarsRefunds;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AdminStarsRefundController extends Controller
{
    public function index(Request $request, TelegramStarsOperations $operations): Response
    {
        $this->owner($request);
        $charges = DB::table('telegram_stars_settlements as settlement')
            ->join('payments as payment', 'payment.id', '=', 'settlement.payment_id')
            ->join('orders as orders', 'orders.id', '=', 'payment.order_id')
            ->join('payment_telegram_stars_details as target', 'target.payment_id', '=', 'payment.id')
            ->leftJoin('telegram_stars_refunds as refund', 'refund.payment_id', '=', 'payment.id')
            ->orderByRaw("CASE WHEN refund.state IN ('pending','review') THEN 0 ELSE 1 END")
            ->orderByDesc('settlement.created_at')->orderBy('payment.id')
            ->simplePaginate(25, ['payment.id', 'orders.invoice_number', 'target.total_xtr', 'payment.status', 'refund.state', 'settlement.late']);
        $reviews = [
            ...DB::table('telegram_stars_payment_reviews')->orderByDesc('created_at')->limit(15)->get(['payment_id', 'reason', 'created_at', 'telegram_update_id']),
            ...DB::table('telegram_stars_refund_reviews')->orderByDesc('created_at')->limit(15)->get(['payment_id', 'reason', 'created_at', 'telegram_update_id']),
        ];

        return $this->privateView(['charges' => $charges, 'reviews' => $reviews, 'counts' => $operations->counts()]);
    }

    public function show(Request $request, Payment $payment, TelegramStarsRefunds $refunds): Response
    {
        $charge = $refunds->preview($this->owner($request), $payment);
        abort_if($charge === null, 404);
        $request->session()->put('stars_refund_preview', ['payment' => $payment->id, 'receipt' => $charge['receipt'], 'expires' => now()->addMinutes(10)->timestamp]);

        return $this->privateView(['charge' => $charge, 'payment' => $payment]);
    }

    public function store(Request $request, Payment $payment, TelegramStarsRefunds $refunds): RedirectResponse
    {
        $actor = $this->owner($request);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'confirm' => ['accepted'],
            'password' => ['required', 'string', 'current_password:web'],
        ]);
        $preview = $request->session()->get('stars_refund_preview');
        $redirect = to_route('admin.stars-refunds.show', $payment);
        if (($preview['payment'] ?? null) !== $payment->id || ($preview['expires'] ?? 0) < now()->timestamp) {
            return $redirect->withErrors(['reason' => 'Buka kembali preview charge sebelum mengirim refund.']);
        }
        $request->session()->forget('stars_refund_preview');
        try {
            $state = $refunds->request($actor, $payment, $preview['receipt'], $validated['reason'], $request->ip());
        } catch (DomainException $error) {
            return $redirect->withErrors(['reason' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $redirect->withErrors(['reason' => 'Status refund belum dapat dipastikan. Rekonsiliasi sebelum mengambil tindakan.']);
        }

        return $redirect->with('status', match ($state) {
            'confirmed' => 'Refund XTR asli dikonfirmasi.',
            'review' => 'Hasil refund belum diketahui. Rekonsiliasi bukti Telegram; jangan kirim ulang.',
            default => 'Refund masih menunggu konfirmasi Telegram.',
        });
    }

    private function owner(Request $request): User
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User && $actor->role?->canRefundStars() === true, 403);

        return $actor;
    }

    private function privateView(array $data): Response
    {
        return response()->view('admin.stars-refunds.show', $data)->header('Cache-Control', 'private, no-store');
    }
}
