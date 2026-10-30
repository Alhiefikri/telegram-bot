<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Storefront\StorefrontShopPaymentStatusRefresh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontShopOrderStatusController extends Controller
{
    public function __invoke(Request $request, Order $order, StorefrontShopPaymentStatusRefresh $refresh): JsonResponse
    {
        $result = $refresh->execute($order, $request->header('X-Storefront-Auto-Poll') === '1');

        return response()
            ->json($result->payload(), $result->statusCode())
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
