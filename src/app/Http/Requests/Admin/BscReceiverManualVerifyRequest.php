<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class BscReceiverManualVerifyRequest extends FormRequest
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
            'high_risk_ack' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'high_risk_ack.required' => 'Manual verified harus dikonfirmasi sebagai jalur high risk.',
            'high_risk_ack.accepted' => 'Manual verified harus dikonfirmasi sebagai jalur high risk.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->withFragment('admin-bsc-settings')
            ->withErrors($validator, 'bsc')
            ->withInput();
    }
}
