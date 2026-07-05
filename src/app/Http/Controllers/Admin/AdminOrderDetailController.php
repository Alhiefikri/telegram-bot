<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminDashboardSalesData;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminOrderDetailController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminDashboardSalesData $salesData): View
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('view', $order);

        $order->load(['customer', 'orderItems', 'payments', 'salesProofPost']);

        return view('admin.orders.show', [
            'order' => $order,
            'customerLabel' => $order->customer?->first_name ?: ($order->customer?->username ? '@'.$order->customer->username : 'Pelanggan'),
            'createdAtLabel' => $order->created_at?->format('d M Y H:i'),
            'total' => Money::idr($order->total_amount),
            'statusLabel' => $order->order_status->value.' / '.$order->payment_status->value,
            'itemLabels' => $order->orderItems->map(fn ($item): string => $item->product_name.' × '.$item->quantity)->all(),
            'paymentLabels' => $order->payments->map(fn ($payment): string => $payment->payment_method_label.' · '.$payment->status->value.' · '.($payment->paid_at?->format('d M Y H:i') ?? 'belum paid'))->all(),
            'paymentAttention' => $salesData->paymentAttention($order),
            'canCancelRelease' => $order->order_status === OrderStatus::PendingPayment && $order->payment_status === PaymentStatus::Pending,
            'salesProof' => $salesData->salesProofState($order, (bool) $user->role?->canManagePaymentSettings()),
        ]);
    }
}
