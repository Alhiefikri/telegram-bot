<?php

namespace App\Http\Controllers;

use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirInteger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PakasirWebhookController extends Controller
{
    public function __invoke(Request $request, PakasirCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->assertProviderPayloadShape($payload);

        $fingerprint = $this->replayFingerprint($payload);
        if (Cache::has($fingerprint)) {
            return response()->json(['success' => true, 'replayed' => true], 202);
        }

        try {
            $processor->processCallback($payload);
        } catch (UnprocessableEntityHttpException $exception) {
            Cache::put($fingerprint, true, now()->addMinutes((int) config('services.pakasir.webhook_replay_ttl_minutes', 10)));

            throw $exception;
        }

        return response()->json(['success' => true]);
    }

    private function assertProviderPayloadShape(array $payload): void
    {
        $validator = Validator::make($payload, [
            'order_id' => ['required', 'string'],
            'amount' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (PakasirInteger::parsePositive($value) === null) {
                        $fail("The {$attribute} field must be a positive integer.");
                    }
                },
            ],
            'project' => ['required', 'string'],
            'status' => ['required', 'string'],
            'payment_method' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException('Invalid Pakasir callback payload.');
        }
    }

    private function replayFingerprint(array $payload): string
    {
        $amount = PakasirInteger::parsePositive($payload['amount'] ?? null);
        if ($amount === null) {
            throw new UnprocessableEntityHttpException('Invalid Pakasir callback payload.');
        }

        $stablePayload = [
            'amount' => $amount,
            'order_id' => (string) $payload['order_id'],
            'project' => (string) $payload['project'],
            'status' => strtolower((string) $payload['status']),
            'payment_method' => ($payload['payment_method'] ?? null) === null ? null : (string) $payload['payment_method'],
        ];

        return 'pakasir:webhook:replay:'.hash('sha256', json_encode($stablePayload, JSON_THROW_ON_ERROR));
    }
}
