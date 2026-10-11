<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerPasswordEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') !== null;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', 'min:12', 'max:128', 'confirmed']];
    }
}
