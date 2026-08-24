<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class BscReceiverVerifyRequest extends FormRequest
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
            'challenge_id' => ['required', 'uuid'],
            'wallet_type' => ['required', 'string', 'in:eoa,erc1271'],
            'signature' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $signature = (string) $value;
                    if (preg_match('/^0x[0-9a-fA-F]+$/', $signature) !== 1 || (strlen($signature) - 2) % 2 !== 0) {
                        $fail('Signature harus berupa hexadecimal bytes dengan prefix 0x.');

                        return;
                    }
                    if (strlen($signature) > 8194) {
                        $fail('Signature smart wallet terlalu panjang.');

                        return;
                    }
                    if ($this->input('wallet_type') === 'eoa' && preg_match('/^0x[0-9a-fA-F]{130}$/', $signature) !== 1) {
                        $fail('Signature EOA harus berupa 65-byte EIP-191 signature.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'challenge_id.required' => 'Challenge ownership wajib dipilih.',
            'challenge_id.uuid' => 'Challenge ownership tidak valid.',
            'wallet_type.in' => 'Tipe wallet ownership tidak valid.',
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
