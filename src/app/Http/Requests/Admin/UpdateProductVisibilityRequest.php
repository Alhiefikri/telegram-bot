<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
        ];
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
