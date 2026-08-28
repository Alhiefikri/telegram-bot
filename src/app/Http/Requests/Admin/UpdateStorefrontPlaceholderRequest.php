<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class UpdateStorefrontPlaceholderRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        $user = $this->attributes->get('adminUser');

        return (bool) $user?->role?->canMutateProducts();
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
            'image.required' => 'Pilih gambar default katalog terlebih dahulu.',
            'image.uploaded' => 'Upload gambar gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'File harus berupa gambar yang valid.',
            'image.mimes' => 'Gambar default harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar default maksimal 5 MB.',
            'image.dimensions' => 'Dimensi gambar default maksimal 6000 x 6000 piksel.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->withFragment('admin-section-products')
            ->withErrors($validator, 'productPlaceholder')
            ->withInput();
    }
}
