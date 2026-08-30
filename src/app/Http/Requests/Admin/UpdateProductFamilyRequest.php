<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductFamily;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UpdateProductFamilyRequest extends AdminProductFamilyFormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->productFamily());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->familyRules();
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->productFamilyUpdateValidationErrors($this, $validator, $this->productFamily());
    }

    private function productFamily(): ProductFamily
    {
        return $this->route('productFamily');
    }
}
