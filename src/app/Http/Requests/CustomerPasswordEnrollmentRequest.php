<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CustomerPasswordEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') !== null;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', Password::min(8)->numbers()->symbols(), 'regex:/\p{Lu}/u', 'max:128', 'confirmed']];
    }
}
