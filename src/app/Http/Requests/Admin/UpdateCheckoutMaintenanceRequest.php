<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class UpdateCheckoutMaintenanceRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canManageStoreOperations();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
            'public_note' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'active.required' => 'Pilih status Checkout Maintenance.',
            'active.boolean' => 'Status Checkout Maintenance tidak valid.',
            'version.required' => 'Muat ulang dashboard sebelum mengubah status Checkout Maintenance.',
            'version.integer' => 'Versi Checkout Maintenance tidak valid.',
            'version.min' => 'Versi Checkout Maintenance tidak valid.',
            'public_note.string' => 'Catatan Checkout Maintenance harus berupa teks.',
            'public_note.max' => 'Catatan Checkout Maintenance maksimal 500 karakter.',
            'confirmed.required' => 'Konfirmasi perubahan Checkout Maintenance terlebih dahulu.',
            'confirmed.accepted' => 'Konfirmasi perubahan Checkout Maintenance terlebih dahulu.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('public_note');

        $this->merge([
            'public_note' => is_string($note) ? trim($note) : $note,
        ]);
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran'])
            ->withErrors($validator, 'checkoutMaintenance')
            ->withInput();
    }
}
