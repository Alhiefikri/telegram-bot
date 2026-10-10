<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\BscPaymentReceiver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class BscReceiverStoreRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManageBscReceivers();
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'regex:/\A0x[0-9a-fA-F]{40}\z/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Label BSC Receiving Address wajib diisi.',
            'label.max' => 'Label BSC Receiving Address maksimal 120 karakter.',
            'address.required' => 'BSC Receiving Address wajib diisi.',
            'address.regex' => 'BSC Receiving Address harus berupa address EVM 0x + 40 karakter hex.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'label' => is_string($this->input('label')) ? trim($this->input('label')) : $this->input('label'),
            'address' => is_string($this->input('address'))
                ? BscPaymentReceiver::normalizeAddressInput($this->input('address'))
                : $this->input('address'),
        ]);
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran'])
            ->withFragment('admin-bsc-settings')
            ->withErrors($validator, 'bsc')
            ->withInput();
    }
}
