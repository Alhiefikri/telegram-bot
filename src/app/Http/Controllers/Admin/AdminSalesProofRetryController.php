<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PublishSalesProofPost;
use App\Models\Order;
use App\Models\SalesProofPost;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminSalesProofRetryController extends Controller
{
    public function __invoke(Request $request, Order $order, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        abort_unless((bool) $user->role?->canManagePaymentSettings(), 403);

        $post = $order->salesProofPost()->first();
        if (! $post || $post->status !== SalesProofPost::STATUS_FAILED) {
            return redirect()->route('admin.dashboard', ['tab' => 'penjualan'])->with('status', 'Sales Proof Post tidak dalam status gagal. Retry tidak dijalankan.');
        }

        PublishSalesProofPost::dispatch($order->id, true, now()->toISOString())->onQueue('notifications')->afterCommit();

        $auditLogger->log('sales_proof.retry_requested', $post, ['status' => $post->status, 'attempts' => $post->attempts], ['order_id' => $order->id], $user, $request->ip());

        return redirect()->route('admin.dashboard', ['tab' => 'penjualan'])->with('status', 'Retry Sales Proof Post dijalankan untuk '.$order->invoice_number.'.');
    }
}
