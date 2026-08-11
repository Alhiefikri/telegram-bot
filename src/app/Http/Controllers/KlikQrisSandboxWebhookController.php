<?php

namespace App\Http\Controllers;

use App\Enums\KlikQrisEnvironment;
use App\Jobs\VerifyKlikQrisWebhookPayment;
use App\Services\KlikQris\KlikQrisCallback;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisStatusUnavailableException;
use App\Services\ProviderVerification\ProviderVerificationProfile;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use UnexpectedValueException;

class KlikQrisSandboxWebhookController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 65_536;

    private const MAX_JSON_DEPTH = 32;

    public function __invoke(
        Request $request,
        KlikQrisProviderReadiness $readiness,
        KlikQrisCallbackProcessor $processor,
    ): JsonResponse {
        return $this->handle($request, $readiness, $processor, KlikQrisEnvironment::Sandbox);
    }

    protected function handle(
        Request $request,
        KlikQrisProviderReadiness $readiness,
        KlikQrisCallbackProcessor $processor,
        KlikQrisEnvironment $environment,
    ): JsonResponse {
        $readinessReport = $readiness->forEnvironment($environment);

        if ($readiness->disabledForEnvironment($environment) || ! $readinessReport->isReady()) {
            throw new NotFoundHttpException;
        }

        if ($environment === KlikQrisEnvironment::Production && ! $this->signatureMatches($request, $readiness)) {
            throw new UnauthorizedHttpException('KlikQRIS', 'Invalid KlikQRIS callback signature.');
        }

        $payload = $this->validatedPayload($request);

        try {
            ProviderVerificationProfiles::using(ProviderVerificationProfile::Webhook, fn () => $processor->processCallback($payload));
        } catch (KlikQrisStatusUnavailableException $exception) {
            report($exception);
            if (! app()->runningUnitTests()) {
                // Webhooks must acknowledge after their bounded synchronous
                // budget; force durable queueing so a sync queue cannot turn
                // this follow-up into another request-time provider call.
                // Replays are harmless because lifecycle transitions dedupe.
                VerifyKlikQrisWebhookPayment::dispatch($payload)->onConnection('database')->onQueue('payments');
            }
        }

        return response()->json(['success' => true]);
    }

    private function signatureMatches(Request $request, KlikQrisProviderReadiness $readiness): bool
    {
        $secret = $readiness->webhookSecret(KlikQrisEnvironment::Production);
        $provided = (string) ($request->header('X-KLIKQRIS-SIGNATURE') ?: $request->header('X-Signature'));
        if ($secret === null || $provided === '') {
            return false;
        }

        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }

    /** @return array<mixed> */
    private function validatedPayload(Request $request): array
    {
        if (! $request->isJson()) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
            throw new HttpException(413, 'KlikQRIS callback payload is too large.');
        }

        if ($this->hasDuplicateTopLevelCriticalKey($content)) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        try {
            $payload = json_decode($content, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        if (! is_array($payload)) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        $validator = Validator::make($payload, [
            'order_id' => ['required', 'string', 'max:255'],
            'amount' => ['required'],
            'total_amount' => ['required'],
            'status' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException('Invalid KlikQRIS callback payload.');
        }

        try {
            KlikQrisCallback::fromPayload($payload);
        } catch (UnexpectedValueException) {
            throw new UnprocessableEntityHttpException('Invalid KlikQRIS callback payload.');
        }

        return $payload;
    }

    private function hasDuplicateTopLevelCriticalKey(string $json): bool
    {
        $critical = array_fill_keys(['order_id', 'amount', 'total_amount', 'status', 'signature'], true);
        $seen = [];
        $depth = 0;
        $length = strlen($json);
        $inString = false;
        $escaped = false;
        $string = '';

        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];

            if ($inString) {
                if ($escaped) {
                    $string .= $char;
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                    $next = $this->nextNonWhitespace($json, $index + 1);
                    if ($depth === 1 && $next === ':' && isset($critical[$string])) {
                        if (isset($seen[$string])) {
                            return true;
                        }

                        $seen[$string] = true;
                    }

                    continue;
                }

                $string .= $char;

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $string = '';

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
            }
        }

        return false;
    }

    private function nextNonWhitespace(string $json, int $offset): ?string
    {
        $length = strlen($json);
        for ($index = $offset; $index < $length; $index++) {
            if (! ctype_space($json[$index])) {
                return $json[$index];
            }
        }

        return null;
    }
}
