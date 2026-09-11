<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

final class ReleaseStoreBalanceTopUpRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canCompensateStoreBalance();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'provider_reference' => ['required', 'string', 'max:191'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'provider_reference.required' => 'Referensi provider wajib diisi.',
            'provider_reference.max' => 'Referensi provider maksimal 191 karakter.',
            'reason.required' => 'Alasan kompensasi wajib diisi.',
            'reason.max' => 'Alasan kompensasi maksimal 500 karakter.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'provider_reference' => is_string($this->input('provider_reference'))
                ? trim((string) $this->input('provider_reference'))
                : $this->input('provider_reference'),
            'reason' => is_string($this->input('reason'))
                ? trim((string) $this->input('reason'))
                : $this->input('reason'),
        ]);
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'balance-issues'])
            ->withErrors($validator, 'balanceCompensation')
            ->withInput();
    }
}
