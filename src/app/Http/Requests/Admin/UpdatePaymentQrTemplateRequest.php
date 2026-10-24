<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

final class UpdatePaymentQrTemplateRequest extends FormRequest
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
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=1600,max_height=1600'],
            'qr_x' => ['required', 'integer', 'min:0', 'max:1599'],
            'qr_y' => ['required', 'integer', 'min:0', 'max:1599'],
            'qr_width' => ['required', 'integer', 'min:240', 'max:1600'],
            'qr_height' => ['required', 'integer', 'min:240', 'max:1600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.uploaded' => 'Upload template QR gagal. Maksimal 8 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'Template QR harus berupa gambar yang valid.',
            'image.mimes' => 'Template QR harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran template QR maksimal 8 MB.',
            'image.dimensions' => 'Dimensi template QR maksimal 1600 x 1600 piksel.',
            'qr_x.required' => 'Isi posisi X area QR.',
            'qr_y.required' => 'Isi posisi Y area QR.',
            'qr_width.required' => 'Isi lebar area QR.',
            'qr_width.min' => 'Area QR minimal 240 piksel agar QR tetap mudah dipindai.',
            'qr_height.required' => 'Isi tinggi area QR.',
            'qr_height.min' => 'Area QR minimal 240 piksel agar QR tetap mudah dipindai.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran'])
            ->withFragment('admin-payment-qr-template')
            ->withErrors($validator, 'qrTemplate')
            ->withInput();
    }
}
