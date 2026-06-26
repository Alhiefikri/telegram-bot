<?php

namespace App\Http\Controllers;

use App\Services\Pakasir\PakasirCallbackProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PakasirWebhookController extends Controller
{
    public function __invoke(Request $request, PakasirCallbackProcessor $processor): JsonResponse
    {
        $processor->processCallback($request->all());

        return response()->json(['success' => true]);
    }
}
