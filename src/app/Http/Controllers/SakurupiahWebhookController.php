<?php

namespace App\Http\Controllers;

use App\Enums\SakurupiahEnvironment;
use App\Services\Sakurupiah\SakurupiahCallback;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahSchemaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class SakurupiahWebhookController
{
    private const MAX_BODY_BYTES = 65_536;

    private const MAX_JSON_DEPTH = 32;

    public function __invoke(
        Request $request,
        SakurupiahCallbackProcessor $processor,
        SakurupiahProviderReadiness $readiness,
    ): JsonResponse {
        $environment = SakurupiahEnvironment::runtime();
        if (! $readiness->configurationReport($environment)->isReady()) {
            throw new NotFoundHttpException('Sakurupiah callback unavailable.');
        }

        if (! $request->isJson()) {
            throw new BadRequestHttpException('Invalid Sakurupiah callback request.');
        }

        $rawBody = $request->getContent();
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Sakurupiah callback payload is too large.');
        }

        if (! $processor->authenticatesCallback(
            $rawBody,
            $request->headers->get('X-Callback-Signature'),
            $environment,
        )) {
            throw new UnauthorizedHttpException('Sakurupiah', 'Invalid Sakurupiah callback signature.');
        }

        $event = $request->headers->get('X-Callback-Event');
        if ($event !== SakurupiahCallback::EVENT_PAYMENT_STATUS) {
            throw new UnprocessableEntityHttpException('Invalid Sakurupiah callback event.');
        }

        if (SakurupiahCallback::hasDuplicateCriticalKeys($rawBody)) {
            throw new BadRequestHttpException('Invalid Sakurupiah callback request.');
        }

        try {
            $payload = json_decode(
                $rawBody,
                true,
                self::MAX_JSON_DEPTH,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('Invalid Sakurupiah callback JSON.', previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new BadRequestHttpException('Invalid Sakurupiah callback JSON.');
        }

        try {
            $callback = SakurupiahCallback::fromPayload($payload, (string) $event);
        } catch (SakurupiahSchemaException $exception) {
            throw new UnprocessableEntityHttpException('Invalid Sakurupiah callback payload.', previous: $exception);
        }

        $processor->processCallback($callback);

        return response()->json(['success' => true]);
    }
}
