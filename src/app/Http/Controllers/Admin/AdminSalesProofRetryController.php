<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SalesProofPost;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminSalesProofRetryController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        AuditLogger $auditLogger,
        AdminDashboardRedirector $redirector,
        TelegramOutboundEffectService $outboundEffects,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('retrySalesProof', $order);

        $post = $order->salesProofPost()->first();
        if (! $post || $post->status !== SalesProofPost::STATUS_FAILED) {
            return $redirector->salesProof($order, $request)->with('status', 'Sales Proof Post tidak dalam status gagal. Retry tidak dijalankan.');
        }

        $oldStatus = $post->status;
        $oldAttempts = $post->attempts;
        $effect = $outboundEffects->materializeSalesProofPostEffect($order, manualRetry: true);
        if ($effect === null) {
            return $redirector->salesProof($order, $request)->with('status', 'Sales Proof Post belum memiliki target Store Channel yang dapat dikirimi.');
        }

        $outboundEffects->dispatch($effect, now()->toISOString(), force: true);

        $auditLogger->log('sales_proof.retry_requested', $post, ['status' => $oldStatus, 'attempts' => $oldAttempts], ['order_id' => $order->id], $user, $request->ip());

        return $redirector->salesProof($order, $request)->with('status', 'Retry Sales Proof Post dijalankan untuk '.$order->invoice_number.'.');
    }
}
