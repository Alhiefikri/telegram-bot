<?php

namespace App\Http\Requests;

use App\Storefront\StorefrontLocale;
use Illuminate\Foundation\Http\FormRequest;

class StorefrontShopCartAddRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.*' => StorefrontLocale::text('choose_cart_product'),
            'quantity.*' => StorefrontLocale::text('cart_quantity_add'),
        ];
    }
}
