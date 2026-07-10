<?php

namespace App\Http\Requests;

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
            'invoice_number.required' => 'Masukkan nomor invoice.',
            'access_key.required' => 'Masukkan recovery key.',
            'access_key.min' => 'Recovery key tidak lengkap.',
        ];
    }
}
