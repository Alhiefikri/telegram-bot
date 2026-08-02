<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminOrderManualRejectController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminOrderOperations $orders): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('cancelPending', $order);

        if (! $orders->hasPendingBinanceMismatch($order)) {
            return back()->withErrors(['order' => 'Pembayaran Binance ini belum memiliki bukti mismatch untuk direview.']);
        }

        if (! $orders->canCancelPendingOrder($order)) {
            return back()->withErrors(['order' => 'Order sudah diproses atau tidak bisa ditolak.']);
        }

        $rejected = $orders->rejectManualOrder($order, $user, $request->ip());

        return redirect()
            ->route('admin.orders.show', $rejected)
            ->with('status', 'Pembayaran ditolak dan stok dilepas.');
    }
}
