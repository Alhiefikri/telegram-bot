<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CustomerApiBoundary;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiOrderCheckout;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\CustomerApi\CustomerApiPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/orders: one Product plus positive quantity per checkout, an
 * existing preference override and the expected totals the caller authorizes.
 * Admission, authentication and the body bound are owned by
 * {@see CustomerApiBoundary}; the durable claim, preference snapshot and
 * canonical checkout are owned by {@see CustomerApiOrderCheckout}.
 */
final class CustomerApiOrderController extends Controller
{
    public function store(Request $request, CustomerApiOrderCheckout $checkout): JsonResponse
    {
        $principal = $request->attributes->get('customer_api_principal');
        if (! $principal instanceof CustomerApiPrincipal) {
            throw new CustomerApiFailure(401, 'unauthenticated', 'A valid API key is required.');
        }

        $result = $checkout->place($principal, CustomerApiOrderRequest::fromRequest($request));

        return response()->json(
            $result->payload((string) $request->attributes->get('customer_api_request_id')),
            $result->replayed ? 200 : 201,
        );
    }
}
