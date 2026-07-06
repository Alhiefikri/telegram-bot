<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Storefront\StorefrontShopSignedInvoice;
use Illuminate\Http\JsonResponse;

class StorefrontShopOrderStatusController extends Controller
{
    public function __invoke(Order $order, StorefrontShopSignedInvoice $signedInvoices): JsonResponse
    {
        $result = $signedInvoices->refreshStatus($order);

        return response()->json($result->payload(), $result->statusCode());
    }
}
