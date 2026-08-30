<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductFamily;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class StoreProductFamilyRequest extends AdminProductFamilyFormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', ProductFamily::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->familyRules();
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->productFamilyCreateValidationErrors($this, $validator);
    }
}
