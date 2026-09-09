<?php

namespace App\Http\Requests;

use App\Models\ManualPaymentSetting;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Storefront\StorefrontUrl;
use App\Support\PaymentProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorefrontShopCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $requiresSakurupiahPhone = $this->requiresSakurupiahPhone();
        $supportChannelRules = ['nullable', 'string', 'required_with:support_contact_value', Rule::in(
            $requiresSakurupiahPhone ? ['whatsapp'] : ['telegram', 'whatsapp'],
        )];
        $supportValueRules = ['nullable', 'string', 'max:120', 'required_with:support_contact_channel'];
        if ($requiresSakurupiahPhone) {
            array_unshift($supportChannelRules, 'required');
            array_unshift($supportValueRules, 'required');
            $supportValueRules[] = 'regex:/^\+?[0-9][0-9 .-]{7,19}$/';
        }

        return [
            'product_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_without:cart_snapshot',
                'prohibits:cart_snapshot',
            ],
            'quantity' => [
                'nullable',
                'integer',
                'min:1',
                'max:20',
                'required_with:product_id',
            ],
            'buyer_name' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_ .-]+$/'],
            'terms_accepted' => ['required', 'accepted'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'support_contact_channel' => $supportChannelRules,
            'support_contact_value' => $supportValueRules,
            'payment_method' => ['nullable', Rule::in(CheckoutPaymentIntents::acceptedPaymentRails())],
            'cart_snapshot' => [
                'nullable',
                'string',
                'max:64',
                'required_without:product_id',
                'prohibits:product_id',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('product_id') && $this->filled('quantity')) {
                $validator->errors()->add(
                    'quantity',
                    'Jumlah hanya boleh dikirim untuk checkout langsung.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Pilih produk yang ingin dibeli.',
            'product_id.required_without' => 'Pilih produk atau gunakan snapshot keranjang untuk checkout.',
            'product_id.prohibits' => 'Pilih produk atau gunakan snapshot keranjang, bukan keduanya.',
            'quantity.*' => 'Jumlah produk tidak valid.',
            'buyer_name.required' => 'Isi nama pembeli dulu.',
            'buyer_name.regex' => 'Gunakan huruf, angka, spasi, titik, strip, atau underscore untuk nama pembeli.',
            'terms_accepted.required' => 'Setujui Syarat Penggunaan dan Kebijakan Refund untuk melanjutkan checkout.',
            'terms_accepted.accepted' => 'Setujui Syarat Penggunaan dan Kebijakan Refund untuk melanjutkan checkout.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'email.max' => 'Email maksimal 254 karakter.',
            'support_contact_channel.*' => 'Pilih kanal bantuan yang tersedia.',
            'support_contact_channel.required' => 'Pilih WhatsApp untuk checkout Sakurupiah.',
            'support_contact_channel.required_with' => 'Pilih kanal bantuan atau kosongkan kontak bantuan.',
            'support_contact_value.required_with' => 'Isi kontak bantuan atau kosongkan kanal bantuan.',
            'support_contact_value.required' => 'Masukkan nomor WhatsApp untuk checkout Sakurupiah.',
            'support_contact_value.regex' => 'Masukkan nomor WhatsApp yang valid untuk checkout Sakurupiah.',
            'payment_method.*' => 'Pilih metode pembayaran yang tersedia.',
            'cart_snapshot.required_without' => 'Gunakan snapshot keranjang atau pilih satu produk untuk checkout.',
            'cart_snapshot.prohibits' => 'Pilih snapshot keranjang atau satu produk, bukan keduanya.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $channel = $this->input('support_contact_channel');
        $value = $this->input('support_contact_value');
        $buyerName = $this->input('buyer_name', $this->input('buyer_handle'));
        $email = $this->input('email');

        $this->merge([
            'buyer_name' => is_string($buyerName) ? trim($buyerName) : $buyerName,
            'support_contact_channel' => is_string($channel) && trim($channel) !== '' ? trim($channel) : null,
            'support_contact_value' => is_string($value) && trim($value) !== '' ? trim($value) : null,
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
        ]);
    }

    private function requiresSakurupiahPhone(): bool
    {
        return ManualPaymentSetting::current()?->paymentProvider() === PaymentProvider::SAKURUPIAH
            && CheckoutPaymentIntents::normalizePaymentRail($this->input('payment_method')) === CheckoutPaymentIntents::RAIL_QRIS;
    }

    protected function getRedirectUrl(): string
    {
        return $this->checkoutRecoveryUrl();
    }

    public function checkoutRecoveryUrl(): string
    {
        if (! $this->filled('product_id')) {
            return route('storefront.shop.checkout.start');
        }

        return app(StorefrontUrl::class)->checkoutRecoveryUrl($this->input('product_id'));
    }
}
