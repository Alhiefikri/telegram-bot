<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class SupplierAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('adminUser')?->role?->canManageStoreOperations() === true;
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();

        return match ($action) {
            'store', 'update' => [
                'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
                'provider' => ['required', Rule::in(['prodseller', 'csxstore', 'arrs', 'warzone', 'aivault'])],
                'api_key' => [$action === 'store' ? 'required' : 'nullable', 'string', 'max:2048', 'not_regex:/[\x00-\x20\x7F]/'],
                'currency' => ['required', Rule::in(['USD', 'USDT'])],
                'currency_confirmed' => ['required', 'boolean'],
                'exchange_rate_idr' => ['required', 'integer', 'min:1', 'max:1000000'],
                'is_active' => ['required', 'boolean'],
                'low_balance_threshold_micros' => ['required', 'integer', 'min:0', 'max:1000000000000'],
                'version' => [$action === 'store' ? 'nullable' : 'required', 'integer', 'min:0'],
                'url' => ['prohibited'], 'base_url' => ['prohibited'],
            ],
            'import' => [
                'supplier_version' => ['required', 'integer', 'min:0'],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'price_amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            ],
            'map' => [
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'supplier_version' => ['required', 'integer', 'min:0'],
                'version' => ['required', 'integer', 'min:0'],
                'equivalent_confirmed' => ['required', 'accepted'],
                'priority' => ['required', 'integer', 'min:0', 'max:10000'],
                'is_active' => ['required', 'boolean'],
            ],
            'price', 'preview' => [
                'version' => ['required', 'integer', 'min:0'],
                'mode' => ['required', Rule::in(['manual', 'percent', 'fixed', 'hybrid'])],
                'markup_basis_points' => ['required', 'integer', 'min:0', 'max:100000'],
                'profit_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'minimum_profit_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'buffer_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'rounding_idr' => ['required', Rule::in([1, 100, 500, 1000])],
                'catalog_item_id' => [$action === 'preview' ? 'required' : 'nullable', 'integer', 'exists:supplier_catalog_items,id'],
            ],
            'operate' => [
                'action' => ['required', Rule::in(['retry', 'recover', 'manual'])],
                'supplier_order_id' => ['required_if:action,recover', 'nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_.:\-]+\z/'],
                'credentials' => ['required_if:action,manual', 'nullable', 'string', 'max:64000'],
            ],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (in_array($this->input('provider'), ['arrs', 'warzone'], true) && ! $this->boolean('currency_confirmed')) {
                $validator->errors()->add('currency_confirmed', 'Konfirmasi mata uang supplier diperlukan.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        // Never flash a request body containing an API key or delivery credentials.
        // Generic field errors also avoid validator values (e.g. an attacker-controlled enum).
        $errors = [];
        foreach ($validator->errors()->keys() as $field) {
            $errors[$field] = ['Nilai bidang tidak valid.'];
        }
        throw new HttpResponseException(response()->json(['message' => 'Data supplier tidak valid.', 'errors' => $errors], 422));
    }
}
