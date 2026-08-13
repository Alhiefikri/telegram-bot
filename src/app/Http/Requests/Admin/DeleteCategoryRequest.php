<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DeleteCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->category());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'confirm_delete' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_delete.required' => 'Konfirmasi penghapusan kategori wajib dikirim.',
            'confirm_delete.accepted' => 'Penghapusan kategori harus dikonfirmasi.',
        ];
    }

    private function category(): Category
    {
        return $this->route('category');
    }
}
