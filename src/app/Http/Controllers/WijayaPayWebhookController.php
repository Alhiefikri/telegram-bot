<?php

namespace App\Http\Controllers;

use App\Services\WijayaPay\WijayaPayCallback;
use App\Services\WijayaPay\WijayaPayCallbackProcessor;
use App\Services\WijayaPay\WijayaPayProviderReadiness;
use App\Services\WijayaPay\WijayaPaySchemaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class WijayaPayWebhookController
{
    private const MAX_BODY_BYTES = 65_536;

    private const MAX_JSON_DEPTH = 32;

    public function __invoke(
        Request $request,
        WijayaPayCallbackProcessor $processor,
        WijayaPayProviderReadiness $readiness,
    ): JsonResponse {
        if (! $readiness->configurationReport()->isReady()) {
            throw new NotFoundHttpException('WijayaPay callback unavailable.');
        }

        if (! $request->isJson()) {
            throw new BadRequestHttpException('Invalid WijayaPay callback request.');
        }

        if (! $this->sourceIsAllowed($request)) {
            throw new UnauthorizedHttpException('WijayaPay', 'WijayaPay callback source is not allowed.');
        }

        $rawBody = $request->getContent();
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'WijayaPay callback payload is too large.');
        }

        if (WijayaPayCallback::hasDuplicateKeys($rawBody)) {
            throw new BadRequestHttpException('Invalid WijayaPay callback request.');
        }

        try {
            $payload = json_decode(
                $rawBody,
                true,
                self::MAX_JSON_DEPTH,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('Invalid WijayaPay callback JSON.', previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new BadRequestHttpException('Invalid WijayaPay callback JSON.');
        }

        try {
            $callback = WijayaPayCallback::fromPayload($payload);
        } catch (WijayaPaySchemaException $exception) {
            throw new UnprocessableEntityHttpException('Invalid WijayaPay callback payload.', previous: $exception);
        }

        if (! $processor->authenticatesCallback(
            $request->headers->get('X-Signature'),
            $callback->refId,
        )) {
            throw new UnauthorizedHttpException('WijayaPay', 'Invalid WijayaPay callback signature.');
        }

        $processor->processCallback($callback);

        // The provider's documented acknowledgement contract.
        return response()->json(['status' => true]);
    }

    private function sourceIsAllowed(Request $request): bool
    {
        if (config('services.wijayapay.callback_enforce_ip_allowlist', false) !== true) {
            return true;
        }

        $configured = config('services.wijayapay.callback_allowed_ips', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $allowed = array_values(array_filter(array_map(
            static fn (mixed $ip): ?string => is_string($ip) ? trim($ip) : null,
            (array) $configured,
        ), static fn (mixed $ip): bool => is_string($ip) && $ip !== ''));

        if ($allowed === []) {
            return false;
        }

        $source = $request->ip();

        return is_string($source) && in_array($source, $allowed, true);
    }
}
