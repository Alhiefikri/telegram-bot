<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Services\Admin\AdminDashboardRedirector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class UpdateCustomerApiAvailabilityRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManageCustomerApiAvailability();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
            'confirmed' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'enabled.required' => 'Pilih status Customer API.',
            'enabled.boolean' => 'Status Customer API tidak valid.',
            'version.required' => 'Muat ulang dashboard sebelum mengubah status Customer API.',
            'version.integer' => 'Versi Customer API tidak valid.',
            'version.min' => 'Versi Customer API tidak valid.',
            'confirmed.required' => 'Konfirmasi perubahan Customer API terlebih dahulu.',
            'confirmed.accepted' => 'Konfirmasi perubahan Customer API terlebih dahulu.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminDashboardRedirector::class)
            ->customerApiAvailability()
            ->withErrors($validator, 'customerApiAvailability')
            ->withInput();
    }
}
