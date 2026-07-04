<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Services\Pakasir\PakasirProviderReadiness;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

class UpdatePaymentModeRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManagePaymentSettings();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_mode' => ['required', 'string', Rule::in(['manual', 'pakasir'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_mode.required' => 'Pilih mode pembayaran.',
            'payment_mode.in' => 'Mode pembayaran tidak valid.',
        ];
    }

    /**
     * @return array<int, callable(ValidationValidator): void>
     */
    public function after(): array
    {
        return [
            function (ValidationValidator $validator): void {
                if ($this->input('payment_mode') !== 'pakasir') {
                    return;
                }

                $readiness = app(PakasirProviderReadiness::class)->checkout();

                if (! $readiness->isReady()) {
                    $validator->errors()->add(
                        'payment_mode',
                        'Mode Pakasir belum bisa diaktifkan. '.$readiness->configurationMessage(),
                    );
                }
            },
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->withErrors($validator, 'paymentMode')
            ->withInput();
    }
}
