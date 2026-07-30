<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminOrderManualCompleteController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminOrderOperations $orders): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('completeManual', $order);

        if (! $orders->hasPendingBinanceMismatch($order)) {
            return back()->withErrors(['order' => 'Pembayaran Binance ini belum memiliki bukti mismatch untuk direview.']);
        }

        if (! $orders->canCompleteManualOrder($order)) {
            return back()->withErrors(['order' => 'Order sudah diproses atau tidak bisa disetujui.']);
        }

        $completed = $orders->completeManualOrder($order, $user, $request->ip());

        return redirect()
            ->route('admin.orders.show', $completed)
            ->with('status', 'Pembayaran disetujui dan stok sedang dikirim ke buyer.');
    }
}
