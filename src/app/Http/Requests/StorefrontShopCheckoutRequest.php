<?php

namespace App\Http\Requests;

use App\Services\Checkout\CheckoutPaymentIntents;
use App\Storefront\StorefrontUrl;
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
        return [
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'buyer_name' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_ .-]+$/'],
            'terms_accepted' => ['required', 'accepted'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'support_contact_channel' => ['nullable', 'string', 'required_with:support_contact_value', Rule::in(['telegram', 'whatsapp'])],
            'support_contact_value' => ['nullable', 'string', 'max:120', 'required_with:support_contact_channel'],
            'payment_method' => ['nullable', Rule::in(CheckoutPaymentIntents::acceptedPaymentRails())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Pilih produk yang ingin dibeli.',
            'quantity.*' => 'Jumlah produk tidak valid.',
            'buyer_name.required' => 'Isi nama pembeli dulu.',
            'buyer_name.regex' => 'Gunakan huruf, angka, spasi, titik, strip, atau underscore untuk nama pembeli.',
            'terms_accepted.required' => 'Setujui Syarat Penggunaan dan Kebijakan Refund untuk melanjutkan checkout.',
            'terms_accepted.accepted' => 'Setujui Syarat Penggunaan dan Kebijakan Refund untuk melanjutkan checkout.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'email.max' => 'Email maksimal 254 karakter.',
            'support_contact_channel.*' => 'Pilih kanal bantuan yang tersedia.',
            'support_contact_channel.required_with' => 'Pilih kanal bantuan atau kosongkan kontak bantuan.',
            'support_contact_value.required_with' => 'Isi kontak bantuan atau kosongkan kanal bantuan.',
            'payment_method.*' => 'Pilih metode pembayaran yang tersedia.',
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

    protected function getRedirectUrl(): string
    {
        return $this->checkoutRecoveryUrl();
    }

    public function checkoutRecoveryUrl(): string
    {
        return app(StorefrontUrl::class)->checkoutRecoveryUrl($this->input('product_id'));
    }
}
