<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\Attributes\RedirectToRoute;
use Illuminate\Foundation\Http\FormRequest;

#[RedirectToRoute('customer.profile.edit')]
final class UpdateCustomerProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'display_name' => ['bail', 'present', 'nullable', 'string', 'max:80', 'regex:/\A[^\p{Cc}\p{Zl}\p{Zp}]+\z/u'],
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(
            ['display_name.present', 'display_name.string', 'display_name.max', 'display_name.regex'],
            __('customer_account.invalid_name'),
        );
    }
}
