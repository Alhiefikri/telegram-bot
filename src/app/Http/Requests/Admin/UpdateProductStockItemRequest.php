<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UpdateProductStockItemRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    protected function prepareForValidation(): void
    {
        $stockItem = $this->route('stockItem');

        if ($stockItem instanceof ProductStockItem && ! $this->has('credential_format')) {
            $this->merge([
                'credential_format' => $stockItem->safeCredentialFormat()?->value ?? 'repair_required',
            ]);
        }
    }

    public function authorize(): bool
    {
        return Gate::allows('adjustStock', $this->product());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'credential_format' => ['required', 'string', 'in:legacy,structured_account'],
            'secret' => ['required_if:credential_format,legacy', 'nullable', 'string', 'max:5000'],
            'email' => ['required_if:credential_format,structured_account', 'nullable', 'string', 'max:254'],
            'password' => ['required_if:credential_format,structured_account', 'nullable', 'string', 'max:1024'],
            'access_method' => ['required_if:credential_format,structured_account', 'nullable', 'string', 'in:none,otp_mail_link,auth_token'],
            'access_value' => ['nullable', 'string', 'max:3500'],
            'tutorial_override' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'secret.required' => 'Isi akun stok tidak boleh kosong.',
            'secret.max' => 'Isi akun stok maksimal 5000 karakter.',
            'email.required_if' => 'Email wajib diisi.',
            'email.max' => 'Email maksimal 254 karakter.',
            'password.required_if' => 'Password wajib diisi.',
            'password.max' => 'Password maksimal 1024 karakter.',
            'access_method.required_if' => 'Metode akses wajib dipilih.',
            'access_value.max' => 'Nilai metode akses terlalu panjang.',
        ];
    }

    /**
     * Legacy override values are preserved internally, never edited through
     * this request.
     *
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $override = $this->input('tutorial_override');
                if (is_string($override) && trim($override) !== '') {
                    $validator->errors()->add(
                        'tutorial_override',
                        'Tutorial akun sekarang mengikuti panduan Product.',
                    );
                }
            },
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->stockItemUpdateValidationErrors($this, $validator, $this->product());
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
