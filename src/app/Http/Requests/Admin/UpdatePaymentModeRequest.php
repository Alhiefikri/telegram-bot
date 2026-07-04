<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Support\PakasirCheckoutFlow;
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

                $missing = [];

                if (blank(config('services.pakasir.slug'))) {
                    $missing[] = 'PAKASIR_SLUG';
                }

                if (blank(config('services.pakasir.api_key'))) {
                    $missing[] = 'PAKASIR_API_KEY';
                }

                if ($missing !== []) {
                    $validator->errors()->add(
                        'payment_mode',
                        'Mode Pakasir belum bisa diaktifkan. Lengkapi '.implode(' dan ', $missing).' di environment.',
                    );
                }

                $checkoutFlow = config('services.pakasir.checkout_flow', PakasirCheckoutFlow::HOSTED);

                if (! PakasirCheckoutFlow::isAllowed($checkoutFlow)) {
                    $validator->errors()->add(
                        'payment_mode',
                        'Mode Pakasir belum bisa diaktifkan. '.PakasirCheckoutFlow::validationMessage(),
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
