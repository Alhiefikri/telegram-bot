<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\Category;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class StoreCategoryRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        return Gate::allows('create', Category::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'category_name' => $this->trimmedStringOrOriginal('category_name'),
            'category_description' => $this->nullableTrimmedString('category_description'),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'category_name' => ['required', 'string', 'max:120'],
            'category_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_name.required' => 'Nama kategori wajib diisi.',
            'category_name.max' => 'Nama kategori maksimal 120 karakter.',
            'category_description.max' => 'Deskripsi kategori maksimal 500 karakter.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->categoryCreateValidationErrors($this, $validator);
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
