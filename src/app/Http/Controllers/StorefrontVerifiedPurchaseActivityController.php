<?php

namespace App\Http\Controllers;

use App\Storefront\VerifiedPurchaseActivity;
use Illuminate\Http\JsonResponse;

class StorefrontVerifiedPurchaseActivityController extends Controller
{
    public function __invoke(VerifiedPurchaseActivity $activity): JsonResponse
    {
        return response()->json([
            'items' => $activity->recent()->all(),
        ])->header('Cache-Control', 'public, max-age=45');
    }
}
