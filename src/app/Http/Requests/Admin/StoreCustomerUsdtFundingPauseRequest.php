<?php

namespace App\Http\Requests\Admin;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Services\Admin\AdminDashboardRedirector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class StoreCustomerUsdtFundingPauseRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:191', 'not_regex:/[\x00-\x1F\x7F]/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan jeda wajib diisi.',
            'reason.string' => 'Alasan jeda tidak valid.',
            'reason.min' => 'Alasan jeda minimal 3 karakter.',
            'reason.max' => 'Alasan jeda maksimal 191 karakter.',
            'reason.not_regex' => 'Alasan jeda harus satu baris tanpa karakter kontrol.',
        ];
    }

    public function scope(): ?CustomerUsdtFundingPauseScope
    {
        $scope = (string) $this->route('scope');

        return CustomerUsdtFundingPauseScope::tryFrom($scope);
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminDashboardRedirector::class)->usdtFundingPolicy()
            ->withErrors($validator, 'usdtFundingPolicy')
            ->withInput();
    }
}
