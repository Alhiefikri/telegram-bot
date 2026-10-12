<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CustomerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', Password::min(8)->numbers()->symbols(), 'regex:/\p{Lu}/u', 'max:128', 'confirmed'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
