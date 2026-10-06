<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyDefinition;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Validator as ValidationValidator;

class UpdateCustomerUsdtFundingPolicyRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManageUsdtFundingPolicy();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'funding_enabled' => ['required', 'boolean'],
            'confirmation_ttl_seconds' => ['required'],
            'application_fee_micro' => ['required'],
            'terms_body' => ['required', 'string'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'funding_enabled.required' => 'Status top-up USDT wajib dipilih.',
            'funding_enabled.boolean' => 'Status top-up USDT tidak valid.',
            'confirmation_ttl_seconds.required' => 'Masa konfirmasi top-up USDT wajib diisi.',
            'application_fee_micro.required' => 'Fee aplikasi top-up USDT wajib diisi.',
            'terms_body.required' => 'Syarat top-up USDT wajib diisi.',
            'terms_body.string' => 'Syarat top-up USDT tidak valid.',
            'version.required' => 'Muat ulang dashboard sebelum mengubah kebijakan top-up USDT.',
            'version.integer' => 'Versi kebijakan top-up USDT tidak valid.',
            'version.min' => 'Versi kebijakan top-up USDT tidak valid.',
        ];
    }

    /** @return array<int, callable(ValidationValidator): void> */
    public function after(): array
    {
        return [
            function (ValidationValidator $validator): void {
                foreach (CustomerUsdtFundingPolicyDefinition::validationErrors(
                    $this->boolean('funding_enabled'),
                    $this->input('confirmation_ttl_seconds'),
                    $this->input('application_fee_micro'),
                    $this->input('terms_body'),
                ) as $key => $message) {
                    $validator->errors()->add($key, $message);
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $ttl = $this->input('confirmation_ttl_seconds');
        $fee = $this->input('application_fee_micro');

        $this->merge([
            'confirmation_ttl_seconds' => is_string($ttl) ? trim($ttl) : $ttl,
            'application_fee_micro' => is_string($fee) ? trim($fee) : $fee,
        ]);
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminDashboardRedirector::class)->usdtFundingPolicy()
            ->withErrors($validator, 'usdtFundingPolicy')
            ->withInput();
    }
}
