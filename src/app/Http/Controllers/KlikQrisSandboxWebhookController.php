<?php

namespace App\Http\Controllers;

use App\Services\KlikQris\KlikQrisCallback;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisStatusUnavailableException;
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

        try {
            $processor->processCallback($payload);
        } catch (KlikQrisStatusUnavailableException $exception) {
            report($exception);
        }

        return response()->json(['success' => true]);
    }

    /** @return array<mixed> */
    private function validatedPayload(Request $request): array
    {
        if (! $request->isJson()) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

        $content = $request->getContent();
        if ($this->hasDuplicateTopLevelCriticalKey($content)) {
            throw new BadRequestHttpException('Invalid KlikQRIS callback request.');
        }

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
