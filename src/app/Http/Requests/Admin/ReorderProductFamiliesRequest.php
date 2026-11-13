<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductFamily;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReorderProductFamiliesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', ProductFamily::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'family_ids' => ['present', 'array', 'list'],
            'family_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_order' => ['present', 'array', 'list'],
            'expected_order.*' => ['required', 'array:id,sort_order'],
            'expected_order.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_order.*.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);
        $exception->response = new JsonResponse([
            'message' => 'Periksa kembali daftar urutan family.',
            'errors' => $validator->errors()->toArray(),
        ], 422);

        throw $exception;
    }
}
