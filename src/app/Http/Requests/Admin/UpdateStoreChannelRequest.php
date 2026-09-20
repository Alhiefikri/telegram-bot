<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class UpdateStoreChannelRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManagePaymentSettings();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'channel_username' => ['nullable', 'string', 'max:255'],
            'channel_link' => ['nullable', 'string', 'max:255'],
            'sales_proof_enabled' => ['nullable', 'boolean'],
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'telegram-channel'])
            ->withErrors($validator, 'storeChannel')
            ->withInput();
    }
}
