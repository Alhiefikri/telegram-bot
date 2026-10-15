<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminOrderCancelReleaseController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminOrderOperations $orders): RedirectResponse|JsonResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('cancelPending', $order);

        if (! $orders->canCancelPendingOrder($order)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Order sudah diproses atau tidak bisa dibatalkan.', 'errors' => ['order' => ['Order sudah diproses atau tidak bisa dibatalkan.']]], 422);
            }

            return back()->withErrors(['order' => 'Order sudah diproses atau tidak bisa dibatalkan.']);
        }

        $cancelled = $orders->cancelPendingOrder($order, $user, $request->ip());
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Order dibatalkan dan stok dilepas.'])->header('Cache-Control', 'no-store, private');
        }

        return redirect()
            ->route('admin.orders.show', $cancelled)
            ->with('status', 'Order '.$cancelled->invoice_number.' dibatalkan dan stok dilepas.');
    }
}
