<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AdminProductFormRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $value = trim((string) $this->input('usdt_price_amount', ''));
        if ($value === '') {
            return;
        }
        if (preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $value, $m)) {
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
            'usdt_price_amount.integer' => 'Harga USDT harus berupa angka dengan maksimal 6 desimal.',
            'description.required' => 'Deskripsi singkat wajib diisi.',
            'delivery_note.max' => 'Catatan pengiriman rahasia maksimal 2000 karakter.',
            'category_id.exists' => 'Kategori tidak valid.',
            'image.uploaded' => 'Upload gambar gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'File harus berupa gambar yang valid.',
            'image.mimes' => 'Gambar produk harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar produk maksimal 5 MB.',
            'image.dimensions' => 'Dimensi gambar produk maksimal 6000 x 6000 piksel.',
        ];
    }
}
