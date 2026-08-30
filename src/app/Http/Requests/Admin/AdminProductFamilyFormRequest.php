<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

abstract class AdminProductFamilyFormRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trimmedStringOrOriginal('name'),
            'description' => $this->nullableTrimmedString('description'),
            'sort_order' => $this->input('sort_order') === null ? 0 : $this->trimmedStringOrOriginal('sort_order'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    protected function familyRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama Product Family wajib diisi.',
            'name.max' => 'Nama Product Family maksimal 160 karakter.',
            'description.max' => 'Deskripsi Product Family maksimal 1000 karakter.',
            'sort_order.required' => 'Urutan Product Family wajib diisi.',
            'sort_order.integer' => 'Urutan Product Family harus berupa angka bulat.',
            'sort_order.min' => 'Urutan Product Family minimal 0.',
            'sort_order.max' => 'Urutan Product Family maksimal 999999.',
            'image.uploaded' => 'Upload gambar family gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'File family harus berupa gambar yang valid.',
            'image.mimes' => 'Gambar family harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar family maksimal 5 MB.',
            'image.dimensions' => 'Dimensi gambar family maksimal 6000 x 6000 piksel.',
        ];
    }

    private function trimmedStringOrOriginal(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }

    private function nullableTrimmedString(string $key): mixed
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
