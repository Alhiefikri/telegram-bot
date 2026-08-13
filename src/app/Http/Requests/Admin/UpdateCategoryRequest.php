<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\Category;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UpdateCategoryRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->category());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trimmedStringOrOriginal('name'),
            'description' => $this->nullableTrimmedString('description'),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.max' => 'Nama kategori maksimal 120 karakter.',
            'description.max' => 'Deskripsi kategori maksimal 500 karakter.',
            'is_active.required' => 'Status kategori wajib dipilih.',
            'is_active.boolean' => 'Status kategori tidak valid.',
            'sort_order.required' => 'Urutan tampil wajib diisi.',
            'sort_order.integer' => 'Urutan tampil harus berupa angka bulat.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->categoryUpdateValidationErrors($this, $validator, $this->category());
    }

    private function category(): Category
    {
        return $this->route('category');
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
