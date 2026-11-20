<?php

namespace App\Http\Requests;

use App\Storefront\StorefrontLocale;
use Illuminate\Foundation\Http\FormRequest;

class StorefrontShopCartUpdateRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:0', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.*' => StorefrontLocale::text('cart_quantity_update'),
        ];
    }
}
