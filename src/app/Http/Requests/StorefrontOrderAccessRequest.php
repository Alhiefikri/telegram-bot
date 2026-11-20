<?php

namespace App\Http\Requests;

use App\Storefront\StorefrontLocale;
use Illuminate\Foundation\Http\FormRequest;

class StorefrontOrderAccessRequest extends FormRequest
{
    protected $redirectRoute = 'storefront.order-access.create';

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
            'invoice_number' => ['required', 'string', 'max:80'],
            'access_key' => ['required', 'string', 'min:12', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice_number.required' => StorefrontLocale::text('invoice_required'),
            'invoice_number.string' => StorefrontLocale::text('invoice_invalid'),
            'invoice_number.max' => StorefrontLocale::text('invoice_invalid'),
            'access_key.required' => StorefrontLocale::text('key_required'),
            'access_key.min' => StorefrontLocale::text('key_short'),
            'access_key.string' => StorefrontLocale::text('key_invalid'),
            'access_key.max' => StorefrontLocale::text('key_invalid'),
        ];
    }
}
