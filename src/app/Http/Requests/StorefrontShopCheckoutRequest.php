<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorefrontShopCheckoutRequest extends FormRequest
{
    protected $redirectRoute = 'storefront.shop.index';

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
            'buyer_handle' => ['required', 'string', 'min:2', 'max:40', 'regex:/^@?[A-Za-z0-9_ .-]+$/'],
            'payment_method' => ['nullable', 'in:qris,binance_pay'],
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
            'buyer_handle.required' => 'Isi username Telegram atau nama pembeli dulu.',
            'buyer_handle.regex' => 'Gunakan huruf, angka, spasi, titik, strip, underscore, atau awali dengan @ untuk username Telegram.',
            'payment_method.*' => 'Pilih metode pembayaran yang tersedia.',
        ];
    }
}
