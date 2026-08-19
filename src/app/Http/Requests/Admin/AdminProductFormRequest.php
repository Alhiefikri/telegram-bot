<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AdminProductFormRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'usage_guide_title' => $this->nullableTrimmedString('usage_guide_title'),
            'usage_guide_url' => $this->nullableTrimmedString('usage_guide_url'),
        ]);

        $value = trim((string) $this->input('usdt_price_amount', ''));
        if ($value === '') {
            return;
        }

        if (str_contains($value, '.')) {
            [$whole, $fraction] = explode('.', $value, 2);

            if ($fraction !== '') {
                $fraction = rtrim($fraction, '0');
                $value = $fraction === '' ? $whole : $whole.'.'.$fraction;
            }
        }

        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $m)) {
            $this->merge(['usdt_price_amount' => ((int) $m[1] * 1_000_000) + (int) str_pad($m[2] ?? '', 6, '0')]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function productRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price_amount' => ['required', 'integer', 'min:0'],
            'usdt_price_amount' => ['nullable', 'integer', 'min:0'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:1000'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
            'usage_guide_title' => ['nullable', 'string', 'max:160', 'required_with:usage_guide_url'],
            'usage_guide_url' => [
                'nullable',
                'string',
                'max:2048',
                'required_with:usage_guide_title',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->isSafeUsageGuideUrl($value)) {
                        $fail('URL panduan harus berupa URL HTTP/HTTPS atau path lokal yang diawali satu garis miring.');
                    }
                },
            ],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama produk wajib diisi.',
            'price_amount.required' => 'Harga wajib diisi.',
            'price_amount.integer' => 'Harga harus berupa angka tanpa pemisah.',
            'usdt_price_amount.integer' => 'Harga USDT harus berupa angka dengan maksimal 3 desimal.',
            'description.required' => 'Deskripsi singkat wajib diisi.',
            'delivery_note.max' => 'Catatan pengiriman rahasia maksimal 2000 karakter.',
            'usage_guide_title.required_with' => 'Judul panduan wajib diisi jika URL panduan diisi.',
            'usage_guide_title.max' => 'Judul panduan maksimal 160 karakter.',
            'usage_guide_url.required_with' => 'URL panduan wajib diisi jika judul panduan diisi.',
            'usage_guide_url.max' => 'URL panduan maksimal 2048 karakter.',
            'category_id.exists' => 'Kategori tidak valid.',
            'image.uploaded' => 'Upload gambar gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'File harus berupa gambar yang valid.',
            'image.mimes' => 'Gambar produk harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar produk maksimal 5 MB.',
            'image.dimensions' => 'Dimensi gambar produk maksimal 6000 x 6000 piksel.',
        ];
    }

    private function nullableTrimmedString(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }

    private function isSafeUsageGuideUrl(string $value): bool
    {
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
