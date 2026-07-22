<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Storefront\StorefrontShopPaymentStatusRefresh;
use Illuminate\Http\JsonResponse;

class StorefrontShopOrderStatusController extends Controller
{
    public function __invoke(Order $order, StorefrontShopPaymentStatusRefresh $refresh): JsonResponse
    {
        $result = $refresh->execute($order);

        return response()->json($result->payload(), $result->statusCode());
    }
}
