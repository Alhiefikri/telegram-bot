<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\CustomerUsdtFundingPolicy;
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
            'preset_amounts' => ['required', 'array', 'min:1', 'max:'.CustomerUsdtFundingPolicy::MAX_PRESETS],
            'preset_amounts.*' => ['required'],
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
            'preset_amounts.required' => 'Daftar preset top-up USDT wajib diisi.',
            'preset_amounts.array' => 'Daftar preset top-up USDT tidak valid.',
            'preset_amounts.min' => 'Daftar preset top-up USDT harus memiliki sedikitnya satu nominal.',
            'preset_amounts.max' => 'Daftar preset top-up USDT maksimal '.CustomerUsdtFundingPolicy::MAX_PRESETS.' nominal.',
            'preset_amounts.*.required' => 'Setiap baris preset top-up USDT wajib diisi.',
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
                    $this->input('preset_amounts'),
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
        $presets = $this->input('preset_amounts');

        if (is_string($presets)) {
            // The textarea accepts one preset per line, comma separated, or
            // both; empty fragments from stray separators are ignored.
            $fragments = preg_split('/(?:\R|,)+/', trim($presets)) ?: [];
            $presets = array_values(array_filter(
                array_map(static fn (string $amount): string => trim($amount), $fragments),
                static fn (string $amount): bool => $amount !== '',
            ));
        } elseif (is_array($presets)) {
            $presets = array_map(
                static fn (mixed $amount): mixed => is_string($amount) ? trim($amount) : $amount,
                $presets,
            );
        }

        $this->merge([
            'confirmation_ttl_seconds' => is_string($ttl) ? trim($ttl) : $ttl,
            'application_fee_micro' => is_string($fee) ? trim($fee) : $fee,
            'preset_amounts' => $presets,
        ]);
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminDashboardRedirector::class)->usdtFundingPolicy()
            ->withErrors($validator, 'usdtFundingPolicy')
            ->withInput();
    }
}
