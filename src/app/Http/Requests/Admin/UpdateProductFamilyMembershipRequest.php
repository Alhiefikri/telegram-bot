<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateProductFamilyMembershipRequest extends FormRequest
{
    use Concerns\RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->product());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_family_id' => $this->input('product_family_id', $this->input('family_id')),
            'family_sort_order' => $this->input('family_sort_order', $this->input('sort_order', 0)),
            'family_option_label' => $this->nullableTrimmedString($this->input('family_option_label', $this->input('option_label'))),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'product_family_id' => ['nullable', 'integer', Rule::exists('product_families', 'id')->whereNull('deleted_at')],
            'family_sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'family_option_label' => ['nullable', 'string', 'max:160'],
            'workspace_version' => [$this->expectsJson() ? 'required' : 'sometimes', 'string', 'size:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_family_id.integer' => 'Product Family tidak valid.',
            'product_family_id.exists' => 'Product Family tidak ditemukan atau sudah dihapus.',
            'family_sort_order.integer' => 'Urutan option harus berupa angka bulat.',
            'family_sort_order.min' => 'Urutan option minimal 0.',
            'family_sort_order.max' => 'Urutan option maksimal 999999.',
            'family_option_label.max' => 'Label option maksimal 160 karakter.',
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->productFamilyMembershipValidationErrors($this, $validator, $this->product());
    }

    private function product(): Product
    {
        return $this->route('product');
    }

    private function nullableTrimmedString(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
