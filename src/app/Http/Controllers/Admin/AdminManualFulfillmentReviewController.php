<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManualFulfillmentReview;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminManualFulfillmentReviewController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ManualFulfillmentReview::class);

        $reviews = ManualFulfillmentReview::query()
            ->with(['order:id,invoice_number,order_status,payment_status', 'payment:id,method,provider,provider_reference,paid_at'])
            ->orderByRaw('case when state = ? then 0 else 1 end', [ManualFulfillmentReview::STATE_PENDING])
            ->latest()
            ->paginate(20);

        return view('admin.manual-fulfillment-reviews.index', compact('reviews'));
    }

    public function show(Request $request, ManualFulfillmentReview $review): View
    {
        Gate::authorize('view', $review);

        $review->load(['order.customer', 'order.orderItems', 'payment', 'actor']);

        return view('admin.manual-fulfillment-reviews.show', [
            'review' => $review,
            'amount' => $this->amount($review->amount, $review->currency),
        ]);
    }

    public function resolve(Request $request, ManualFulfillmentReview $review, ManualFulfillmentResolutionService $resolutions): RedirectResponse
    {
        Gate::authorize('resolve', $review);
        $data = $request->validate([
            'action' => ['required', 'in:fulfill,refund,reject'],
            'reason' => ['required', 'string', 'max:2000'],
            'refund_provider_reference' => ['nullable', 'string', 'max:255'],
            'refund_provider_result' => ['nullable', 'in:success,failure,uncertain'],
        ]);

        try {
            $resolved = $resolutions->resolve(
                $review,
                $request->attributes->get('adminUser'),
                $data['action'],
                $data['reason'],
                $data['refund_provider_reference'] ?? null,
                $data['refund_provider_result'] ?? null,
                $request->ip(),
            );
        } catch (\Throwable $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return redirect()->route('admin.manual-fulfillment-reviews.show', $resolved)->with('status', 'Review resolved as '.$resolved->state.'.');
    }

    private function amount(int $amount, string $currency): string
    {
        return strtoupper($currency) === 'USDT' ? Money::usdt($amount) : Money::idr($amount);
    }
}
