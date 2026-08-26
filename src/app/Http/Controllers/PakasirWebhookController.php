<?php

namespace App\Http\Controllers;

use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirInteger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PakasirWebhookController extends Controller
{
    public function __invoke(Request $request, PakasirCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->assertProviderPayloadShape($payload);

        $processor->processCallback($payload);

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
}
