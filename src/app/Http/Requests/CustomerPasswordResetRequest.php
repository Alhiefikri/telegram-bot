<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CustomerPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'max:128'],
            'password' => ['required', 'string', Password::min(8)->numbers()->symbols(), 'regex:/\p{Lu}/u', 'max:128', 'confirmed'],
        ];
    }
}
