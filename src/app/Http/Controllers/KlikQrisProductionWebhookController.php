<?php

namespace App\Http\Controllers;

use App\Enums\KlikQrisEnvironment;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KlikQrisProductionWebhookController extends KlikQrisSandboxWebhookController
{
    public function __invoke(
        Request $request,
        KlikQrisProviderReadiness $readiness,
        KlikQrisCallbackProcessor $processor,
    ): JsonResponse {
        return $this->handle($request, $readiness, $processor, KlikQrisEnvironment::Production);
    }
}
