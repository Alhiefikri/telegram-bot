<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramWebhookIngress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramWebhookIngress $webhookIngress) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->webhookIngress->receive($request);

        return response()->json($result->body, $result->status);
    }
}
