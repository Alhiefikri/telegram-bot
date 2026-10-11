<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerEmailOtpConsumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'max:128'],
        ];
    }
}
