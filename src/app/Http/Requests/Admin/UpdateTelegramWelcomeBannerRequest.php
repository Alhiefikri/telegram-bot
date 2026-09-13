<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

final class UpdateTelegramWelcomeBannerRequest extends FormRequest
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
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Pilih gambar Welcome Banner terlebih dahulu.',
            'image.uploaded' => 'Upload Welcome Banner gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'File Welcome Banner harus berupa gambar yang valid.',
            'image.mimes' => 'Welcome Banner harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran Welcome Banner maksimal 5 MB.',
            'image.dimensions' => 'Dimensi Welcome Banner maksimal 6000 x 6000 piksel.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'telegram-channel'])
            ->withFragment('admin-section-telegram-welcome-banner')
            ->withErrors($validator, 'welcomeBanner')
            ->withInput();
    }
}
