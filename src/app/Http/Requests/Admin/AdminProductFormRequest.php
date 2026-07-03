<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AdminProductFormRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function productRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price_amount' => ['required', 'integer', 'min:0'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:1000'],
            'features' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'string', 'max:2000'],
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
            'description.required' => 'Deskripsi singkat wajib diisi.',
            'category_id.exists' => 'Kategori tidak valid.',
        ];
    }
}
