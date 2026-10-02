<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateProductVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->product());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'return' => ['nullable', Rule::in(['supplier'])],
        ];
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
