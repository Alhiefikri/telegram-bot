<?php

namespace App\Http\Controllers;

use App\Services\KlikQris\KlikQrisCallback;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use UnexpectedValueException;

class KlikQrisSandboxWebhookController extends Controller
{
    public function __invoke(Request $request, KlikQrisCallbackProcessor $processor): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        $processor->processCallback($payload);

        return response()->json(['success' => true]);
    }

    /** @return array<mixed> */
    private function validatedPayload(Request $request): array
    {
        if (! $request->isJson()) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        $content = $request->getContent();
        json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        $validator = Validator::make($payload, [
            'order_id' => ['required', 'string'],
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
}
