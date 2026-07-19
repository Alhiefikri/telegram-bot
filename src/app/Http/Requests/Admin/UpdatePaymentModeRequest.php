<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Services\Pakasir\PakasirProviderReadiness;
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
            'pakasir_checkout_flow' => [Rule::requiredIf(fn (): bool => $this->input('payment_mode') === 'pakasir'), 'nullable', 'string', Rule::in(PakasirCheckoutFlow::allowed())],
            'binance_pay_enabled' => ['sometimes', 'boolean'],
            'qris_payment_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'binance_payment_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
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
            'pakasir_checkout_flow.required' => 'Pilih jenis pembayaran Pakasir.',
            'pakasir_checkout_flow.in' => 'Jenis pembayaran Pakasir tidak valid.',
            'qris_payment_ttl_minutes.integer' => 'Batas waktu QRIS harus berupa jumlah menit.',
            'qris_payment_ttl_minutes.min' => 'Batas waktu QRIS minimal 1 menit.',
            'qris_payment_ttl_minutes.max' => 'Batas waktu QRIS maksimal 1440 menit.',
            'binance_payment_ttl_minutes.integer' => 'Batas waktu Binance Pay harus berupa jumlah menit.',
            'binance_payment_ttl_minutes.min' => 'Batas waktu Binance Pay minimal 1 menit.',
            'binance_payment_ttl_minutes.max' => 'Batas waktu Binance Pay maksimal 1440 menit.',
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

                $checkoutFlow = (string) ($this->input('pakasir_checkout_flow') ?: PakasirCheckoutFlow::HOSTED);
                $readiness = app(PakasirProviderReadiness::class)->checkout($checkoutFlow);

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
