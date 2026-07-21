<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class UpdateSupportSettingsRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManagePaymentSettings();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'whatsapp_support_url' => ['nullable', 'url:http,https', 'max:255'],
            'whatsapp_support_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\s-]*$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'whatsapp_support_url.url' => 'URL WhatsApp harus diawali http:// atau https://.',
            'whatsapp_support_url.max' => 'URL WhatsApp maksimal 255 karakter.',
            'whatsapp_support_number.max' => 'Nomor WhatsApp maksimal 40 karakter.',
            'whatsapp_support_number.regex' => 'Nomor WhatsApp hanya boleh berisi angka, spasi, +, titik, tanda kurung, atau strip.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->withErrors($validator, 'supportSettings')
            ->withInput();
    }
}
