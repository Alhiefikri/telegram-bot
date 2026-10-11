<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class AdminOrderCredentialMailResendController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminOrderOperations $orders): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('resendCredentialMail', $order);

        try {
            $orders->resendCredentialMail($order, $user, $request->ip());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('status', 'Email data akun dijadwalkan ulang ke alamat yang tercatat di pesanan ini.');
    }
}
