<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }
}
