<?php

namespace App\Http\Controllers;

use App\Services\Customer\CustomerAccountOverviewReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerIdentityController extends Controller
{
    public function __invoke(Request $request, CustomerAccountOverviewReader $accounts): JsonResponse
    {
        $customer = $request->user('customer');
        $identity = $customer === null ? null : $accounts->identity($customer);

        return response()->json([
            'authenticated' => $identity !== null,
            'name' => $identity['name'] ?? null,
            'initial' => $identity['initial'] ?? null,
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
