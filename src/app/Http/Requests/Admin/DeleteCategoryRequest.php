<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\Category;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DeleteCategoryRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

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

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->categoryDeleteValidationErrors($validator);
    }

    private function category(): Category
    {
        return $this->route('category');
    }
}
