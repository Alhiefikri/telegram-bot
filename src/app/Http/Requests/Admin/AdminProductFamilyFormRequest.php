<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

abstract class AdminProductFamilyFormRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trimmedStringOrOriginal('name'),
            'description' => $this->nullableTrimmedString('description'),
            ...($this->exists('icon') ? ['icon' => $this->nullableTrimmedString('icon')] : []),
            'sort_order' => $this->input('sort_order') === null ? 0 : $this->trimmedStringOrOriginal('sort_order'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    protected function familyRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:160',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    $normalized = Str::slug($value);
                    if ($normalized === '') {
                        $fail('Slug harus berisi huruf atau angka.');

                        return;
                    }

                    $family = $this->route('productFamily');
                    if (! $family instanceof ProductFamily) {
                        return;
                    }

                    if (ProductFamily::withTrashed()
                        ->where('slug', $normalized)
                        ->whereKeyNot($family->getKey())
                        ->exists()) {
                        $fail('Slug sudah digunakan family lain.');
                    }

                    if (ProductFamilySlugRedirect::query()
                        ->where('old_slug', $normalized)
                        ->where('product_family_id', '!=', $family->getKey())
                        ->exists()) {
                        $fail('Slug masih menjadi alamat lama family lain.');
                    }
                },
            ],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'telegram_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama Product Family wajib diisi.',
            'name.max' => 'Nama Product Family maksimal 160 karakter.',
            'icon.max' => 'Ikon katalog family maksimal 32 karakter.',
            'sort_order.required' => 'Urutan Product Family wajib diisi.',
            'sort_order.integer' => 'Urutan Product Family harus berupa angka bulat.',
            'sort_order.min' => 'Urutan Product Family minimal 0.',
            'sort_order.max' => 'Urutan Product Family maksimal 999999.',
            'image.uploaded' => 'Upload gambar family gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'File family harus berupa gambar yang valid.',
            'image.mimes' => 'Gambar family harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar family maksimal 5 MB.',
            'image.dimensions' => 'Dimensi gambar family maksimal 6000 x 6000 piksel.',
            'telegram_image.uploaded' => 'Upload gambar Telegram family gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'telegram_image.image' => 'File gambar Telegram family harus berupa gambar yang valid.',
            'telegram_image.mimes' => 'Gambar Telegram family harus JPG, PNG, atau WebP.',
            'telegram_image.max' => 'Ukuran gambar Telegram family maksimal 5 MB.',
            'telegram_image.dimensions' => 'Dimensi gambar Telegram family maksimal 6000 x 6000 piksel.',
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
