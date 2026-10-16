<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductWorkspaceData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UpdateProductRequest extends AdminProductFormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->product());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->productRules() + ['is_active' => ['sometimes', 'boolean']];
        if (! $this->exists('workspace_section')) {
            return $rules;
        }

        $section = $this->input('workspace_section');
        $fields = is_string($section) ? (AdminProductWorkspaceData::SECTIONS[$section] ?? []) : [];
        if ($section === 'price') {
            $fields = [...$fields, 'quantity_price_tiers_present', 'quantity_price_tiers', 'quantity_price_tiers.*', 'quantity_price_tiers.*.minimum_quantity', 'quantity_price_tiers.*.price_amount', 'quantity_price_tiers.*.usdt_price_amount'];
        }
        if ($section === 'appearance') {
            $fields = [...$fields, 'image', 'telegram_image', 'telegram_channel_image'];
        }
        $rules = array_intersect_key($rules, array_flip($fields));
        foreach ($rules as $field => &$fieldRules) {
            if (! str_contains($field, '*')) {
                array_unshift($fieldRules, 'sometimes');
            }
        }
        unset($fieldRules);

        return $rules + [
            'workspace_section' => ['required', 'string', 'in:detail,price,appearance'],
            'workspace_version' => ['required', 'string', 'size:64'],
        ];
    }

    public function hasQuantityPriceTiersInput(): bool
    {
        return (! $this->exists('workspace_section') || $this->input('workspace_section') === 'price')
            && parent::hasQuantityPriceTiersInput();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if ($this->input('workspace_section') === 'price') {
            foreach (['price_amount', 'usdt_price_amount', 'reseller_price_amount', 'reseller_usdt_price_amount'] as $field) {
                if (! $this->exists($field)) {
                    $this->merge([$field => $this->product()->getAttribute($field)]);
                }
            }
        }
        if ($this->input('workspace_section') === 'detail') {
            foreach (['description_ms', 'description_en', 'warranty_policy_ms', 'warranty_policy_en', 'usage_guide_title', 'usage_guide_url'] as $field) {
                if (! $this->exists($field)) {
                    $this->merge([$field => $this->product()->getAttribute($field)]);
                }
            }
        }
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->productUpdateValidationErrors($this, $validator, $this->product());
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
