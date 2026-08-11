<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManualFulfillmentReview;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminManualFulfillmentReviewController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ManualFulfillmentReview::class);

        $reviews = ManualFulfillmentReview::query()
            ->where('state', ManualFulfillmentReview::STATE_PENDING)
            ->with(['order:id,invoice_number,order_status,payment_status', 'payment:id,method,provider,provider_reference,paid_at'])
            ->oldest()
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

    private function amount(int $amount, string $currency): string
    {
        return strtoupper($currency) === 'USDT' ? Money::usdt($amount) : Money::idr($amount);
    }
}
