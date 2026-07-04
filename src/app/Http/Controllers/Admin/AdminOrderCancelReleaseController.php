<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminOrderCancelReleaseController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminOrderOperations $orders): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('cancelPending', $order);

        if (! $orders->canCancelPendingOrder($order)) {
            return back()->withErrors(['order' => 'Order sudah diproses atau tidak bisa dibatalkan.']);
        }

        $cancelled = $orders->cancelPendingOrder($order, $user, $request->ip());

        return redirect()
            ->route('admin.orders.show', $cancelled)
            ->with('status', 'Order '.$cancelled->invoice_number.' dibatalkan dan stok dilepas.');
    }
}
