<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Admin\AdminPaymentOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class AdminPaymentReconcileController extends Controller
{
    public function __invoke(
        Request $request,
        Payment $payment,
        AdminPaymentOperations $operations,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('reconcile', $payment);

        if (! $operations->canReconcile($payment)) {
            return back()->withErrors(['payment' => 'Payment ini tidak dapat direkonsiliasi dari dashboard.']);
        }

        try {
            $operations->reconcile($payment, $user, $request->ip());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['payment' => 'Rekonsiliasi gagal. Payment tetap pada status aman dan akan dicoba lagi oleh jadwal otomatis.']);
        }

        return redirect()
            ->route('admin.orders.show', $payment->order_id)
            ->with('status', 'Rekonsiliasi payment selesai. Cek observasi provider pada detail payment.');
    }
}
