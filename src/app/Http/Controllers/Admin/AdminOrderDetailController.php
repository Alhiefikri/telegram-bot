<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
            'auditEntries' => $this->auditEntries($order),
        ]);
    }

    private function auditEntries(Order $order): array
    {
        return AuditLog::query()
            ->with('user:id,name,email')
            ->where('subject_type', Order::class)
            ->where('subject_id', (string) $order->getKey())
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'label' => $this->auditActionLabel((string) $log->action),
                'actor' => $log->user?->name ?: $log->user?->email ?: 'Sistem',
                'created_at' => $log->created_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
                'ip_address' => $log->ip_address,
            ])
            ->all();
    }

    private function auditActionLabel(string $action): string
    {
        return match ($action) {
            'order.pending_cancelled_released' => 'Dibatalkan & stok dilepas',
            'order.manual_completed' => 'Diselesaikan manual',
            'order.manual_rejected' => 'Ditolak manual',
            'order.receipt_resent' => 'Receipt dikirim ulang',
            default => str_replace(['.', '_'], ' ', $action),
        };
    }
}
