<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
