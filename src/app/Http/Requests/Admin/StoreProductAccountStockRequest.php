<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StoreProductAccountStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('adjustStock', $this->product());
    }

    /**
     * Keep request validation structural. ProductStockIntake owns the
     * cross-item and conditional credential rules so direct module callers
     * receive the same safe error contract.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'accounts' => ['required', 'array', 'min:1', 'max:20'],
            'accounts.*' => ['required', 'array'],
            'accounts.*.email' => ['nullable', 'string', 'max:254'],
            'accounts.*.password' => ['nullable', 'string', 'max:1024'],
            'accounts.*.access_method' => ['nullable', 'string', 'in:none,otp_mail_link,auth_token'],
            'accounts.*.access_value' => ['nullable', 'string', 'max:3500'],
            'accounts.*.tutorial_override' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operation_key.required' => 'Kunci operasi wajib diisi.',
            'operation_key.uuid' => 'Kunci operasi tidak valid.',
            'accounts.required' => 'Input Akun wajib diisi.',
            'accounts.array' => 'Input Akun tidak valid.',
            'accounts.min' => 'Input Akun harus berisi minimal satu akun.',
            'accounts.max' => 'Input Akun maksimal 20 akun.',
            'accounts.*.email.max' => 'Email maksimal 254 karakter.',
            'accounts.*.password.max' => 'Password maksimal 1024 karakter.',
            'accounts.*.access_value.max' => 'Nilai metode akses terlalu panjang.',
            'accounts.*.tutorial_override.max' => 'Tutorial tambahan maksimal 2000 karakter.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);
        $exception->response = new JsonResponse([
            'message' => 'Data Input Akun tidak valid.',
            'errors' => $validator->errors()->toArray(),
        ], 422);

        throw $exception;
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
