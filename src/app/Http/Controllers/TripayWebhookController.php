<?php

namespace App\Http\Controllers;

use App\Services\Tripay\TripayCallbackProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripayWebhookController extends Controller
{
    public function __invoke(Request $request, TripayCallbackProcessor $processor): JsonResponse
    {
        $processor->processCallback($request->getContent(), $request->header('X-Callback-Signature'), $request->header('X-Callback-Event'));

        return response()->json(['success' => true]);
    }
}
