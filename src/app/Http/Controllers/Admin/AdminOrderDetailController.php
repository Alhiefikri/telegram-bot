<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminDashboardSalesData;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminOrderDetailController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminDashboardSalesData $salesData): View
    {
        $user = $request->attributes->get('adminUser');
        abort_unless((bool) $user->role?->canViewOrdersAndPayments(), 403);

        $order->load(['customer', 'orderItems', 'payments', 'salesProofPost']);

        return view('admin.orders.show', [
            'order' => $order,
            'customerLabel' => $order->customer?->first_name ?: ($order->customer?->username ? '@'.$order->customer->username : 'Pelanggan'),
            'total' => Money::idr($order->total_amount),
            'salesProof' => $salesData->salesProofState($order, (bool) $user->role?->canManagePaymentSettings()),
        ]);
    }
}
