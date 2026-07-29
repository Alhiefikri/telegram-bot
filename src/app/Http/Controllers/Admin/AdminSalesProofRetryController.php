<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PublishSalesProofPost;
use App\Models\Order;
use App\Models\SalesProofPost;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminSalesProofRetryController extends Controller
{
    public function __invoke(Request $request, Order $order, AuditLogger $auditLogger, AdminDashboardRedirector $redirector): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('retrySalesProof', $order);

        $post = $order->salesProofPost()->first();
        if (! $post || $post->status !== SalesProofPost::STATUS_FAILED) {
            return $redirector->salesProof($order, $request)->with('status', 'Sales Proof Post tidak dalam status gagal. Retry tidak dijalankan.');
        }

        PublishSalesProofPost::dispatch($order->id, true, now()->toISOString())->onQueue('notifications')->afterCommit();

        $auditLogger->log('sales_proof.retry_requested', $post, ['status' => $post->status, 'attempts' => $post->attempts], ['order_id' => $order->id], $user, $request->ip());

        return $redirector->salesProof($order, $request)->with('status', 'Retry Sales Proof Post dijalankan untuk '.$order->invoice_number.'.');
    }
}
