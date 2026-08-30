<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorefrontWaitlistRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Isi alamat email untuk menerima notifikasi.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'email.max' => 'Email maksimal 254 karakter.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) && trim($email) !== ''
                ? mb_strtolower(trim($email))
                : $email,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        $product = $this->route('product');

        return $product !== null
            ? route('storefront.products.show', ['product' => $product]).'#waitlist'
            : url('/');
    }
}
