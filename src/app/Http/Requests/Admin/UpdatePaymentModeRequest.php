<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Support\PaymentProvider;
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

        if ($this->isProviderSwitch()) {
            return (bool) $user?->role?->canSwitchPaymentProvider();
        }

        return (bool) $user?->role?->canManagePaymentSettings();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'update_scope' => ['sometimes', 'string', Rule::in(['provider_switch', 'binance_settings', 'binance_verification', 'bsc_settings'])],
            'payment_mode' => ['required', 'string', Rule::in(PaymentProvider::allowed())],
            'payment_provider_version' => ['sometimes', 'integer', 'min:0'],
            'confirm_provider_switch' => $this->isProviderSwitch() ? ['required', 'accepted'] : ['sometimes'],
            'binance_pay_enabled' => ['sometimes', 'boolean'],
            'binance_verification_mode' => ['sometimes', 'string', Rule::in(['amount_unique', 'order_id'])],
            'qris_payment_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'binance_payment_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'bsc_payment_enabled' => ['sometimes', 'boolean'],
            'bsc_payment_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'bsc_min_confirmations' => ['sometimes', 'integer', 'min:3', 'max:100'],
            'bsc_require_finalized_block' => ['sometimes', 'boolean'],
            'bsc_confirmation_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'bsc_unique_amount_increment_micro' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'bsc_hash_attempt_limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_mode.required' => 'Pilih provider pembayaran.',
            'payment_mode.in' => 'Provider pembayaran tidak valid.',
            'confirm_provider_switch.required' => 'Konfirmasi perubahan provider sebelum melanjutkan.',
            'confirm_provider_switch.accepted' => 'Konfirmasi perubahan provider sebelum melanjutkan.',
            'binance_verification_mode.in' => 'Mode verifikasi Binance tidak valid.',
            'qris_payment_ttl_minutes.integer' => 'Batas waktu QRIS harus berupa jumlah menit.',
            'qris_payment_ttl_minutes.min' => 'Batas waktu QRIS minimal 1 menit.',
            'qris_payment_ttl_minutes.max' => 'Batas waktu QRIS maksimal 1440 menit.',
            'binance_payment_ttl_minutes.integer' => 'Batas waktu Binance Pay harus berupa jumlah menit.',
            'binance_payment_ttl_minutes.min' => 'Batas waktu Binance Pay minimal 1 menit.',
            'binance_payment_ttl_minutes.max' => 'Batas waktu Binance Pay maksimal 1440 menit.',
            'bsc_payment_ttl_minutes.integer' => 'Batas waktu BSC harus berupa jumlah menit.',
            'bsc_payment_ttl_minutes.min' => 'Batas waktu BSC minimal 1 menit.',
            'bsc_payment_ttl_minutes.max' => 'Batas waktu BSC maksimal 1440 menit.',
            'bsc_min_confirmations.min' => 'Minimum konfirmasi BSC minimal 3.',
            'bsc_min_confirmations.max' => 'Minimum konfirmasi BSC maksimal 100.',
            'bsc_confirmation_grace_minutes.max' => 'Grace konfirmasi BSC maksimal 60 menit.',
            'bsc_unique_amount_increment_micro.min' => 'Increment nominal unik BSC harus lebih besar dari 0.',
            'bsc_hash_attempt_limit.min' => 'Batas percobaan hash BSC minimal 1.',
        ];
    }

    /** @return array<int, callable(ValidationValidator): void> */
    public function after(): array
    {
        return [
            function (ValidationValidator $validator): void {
                if (! $this->isProviderSwitch()) {
                    return;
                }

                if ($this->input('payment_mode') === PaymentProvider::KLIKQRIS) {
                    $readiness = app(KlikQrisProviderReadiness::class)->checkout();
                    if (! $readiness->isReady()) {
                        $validator->errors()->add(
                            'payment_mode',
                            'Provider KlikQRIS belum bisa diaktifkan. '.$readiness->configurationMessage(),
                        );
                    }
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

    private function isProviderSwitch(): bool
    {
        return ! in_array($this->input('update_scope'), ['binance_settings', 'binance_verification', 'bsc_settings'], true);
    }
}
