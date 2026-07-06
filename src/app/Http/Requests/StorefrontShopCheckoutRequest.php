<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'buyer_handle' => ['required', 'string', 'min:2', 'max:40', 'regex:/^@?[A-Za-z0-9_ .-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Pilih produk yang ingin dibeli.',
            'product_id.exists' => 'Produk tidak ditemukan atau sudah tidak tersedia.',
            'quantity.*' => 'Jumlah produk tidak valid.',
            'buyer_handle.required' => 'Isi username Telegram atau nama pembeli dulu.',
            'buyer_handle.regex' => 'Gunakan huruf, angka, spasi, titik, strip, underscore, atau awali dengan @ untuk username Telegram.',
        ];
    }

    /**
     * @return array{display_name: string, telegram_username: ?string}
     */
    public function buyerHandle(): array
    {
        $value = trim((string) $this->input('buyer_handle'));

        if (preg_match('/^@?[A-Za-z0-9_]{5,32}$/', $value) === 1) {
            $username = ltrim($value, '@');

            return [
                'display_name' => $username,
                'telegram_username' => $username,
            ];
        }

        return [
            'display_name' => $value,
            'telegram_username' => null,
        ];
    }
}
