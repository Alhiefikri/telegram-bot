<?php

namespace App\Http\Requests\Admin;

use App\Enums\SupplierProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class SupplierAdminRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! in_array($this->route()->getActionMethod(), ['store', 'update'], true)) {
            return;
        }

        $provider = $this->input('provider');
        if (is_string($provider) && ($selected = SupplierProvider::tryFrom($provider)) !== null) {
            if (! $this->has('name')) {
                $this->merge(['name' => $selected->label()]);
            }
            if (! $selected->requiresCurrencyConfirmation()) {
                if (! $this->has('currency')) {
                    $this->merge(['currency' => $selected->defaultCurrency()]);
                }
                if (! $this->has('currency_confirmed')) {
                    $this->merge(['currency_confirmed' => true]);
                }
                if ($selected === SupplierProvider::BuyMail && ! $this->has('exchange_rate_idr')) {
                    $this->merge(['exchange_rate_idr' => 1]);
                }
            }
        }
    }

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
                'provider' => ['required', Rule::enum(SupplierProvider::class)],
                'api_key' => [$action === 'store' ? 'required' : 'nullable', 'string', 'max:2048', 'not_regex:/[\x00-\x20\x7F]/'],
                'webhook_secret' => ['nullable', 'string', 'max:256', 'regex:/\Awhsec_[A-Za-z0-9_-]{8,240}\z/D'],
                'currency' => ['required', Rule::in(['USD', 'USDT', 'IDR'])],
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
            'move' => [
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'direction' => ['required', Rule::in(['up', 'down'])],
                'supplier_version' => ['required', 'integer', 'min:0'],
                'version' => ['required', 'integer', 'min:0'],
            ],
            'detach' => [
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'source_id' => ['required', 'integer', 'min:1'],
                'supplier_version' => ['required', 'integer', 'min:0'],
                'version' => ['required', 'integer', 'min:0'],
            ],
            'price', 'preview' => [
                'version' => ['required', 'integer', 'min:0'],
                'mode' => ['required', Rule::in(['manual', 'percent', 'fixed', 'hybrid'])],
                'markup_basis_points' => ['required', 'integer', 'min:0', 'max:100000'],
                'profit_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'minimum_profit_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'rounding_idr' => ['required', Rule::in([1, 100, 500, 1000])],
                'catalog_item_id' => [$action === 'preview' ? 'required' : 'nullable', 'integer', 'exists:supplier_catalog_items,id'],
            ],
            'operate' => [
                'action' => ['required', Rule::in(['retry', 'recover', 'manual', 'no_debit', 'debit_confirmed', 'history', 'history_confirm'])],
                'purchase_id' => ['required_if:action,no_debit,debit_confirmed,history,history_confirm', 'nullable', 'integer', 'min:1'],
                'purchase_version' => [Rule::requiredIf(fn () => $this->input('action') === 'history_confirm' || ($this->input('action') === 'history' && $this->route('fulfillment')?->currentPurchase?->supplier?->provider === 'csxstore')), 'nullable', 'date_format:Y-m-d H:i:s'],
                'supplier_order_id' => ['required_if:action,recover,debit_confirmed,history_confirm', 'nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_.:\-]+\z/'],
                'credentials' => ['required_if:action,manual', 'nullable', 'string', 'max:64000'],
                'proof_source' => ['required_if:action,no_debit', 'nullable', Rule::in(['provider_case', 'provider_statement', 'provider_history'])],
                'proof_reference' => [Rule::requiredIf(fn () => $this->input('action') === 'no_debit' && in_array($this->input('proof_source'), ['provider_case', 'provider_statement'], true)), 'nullable', 'string', 'max:255'],
                'proof_note' => ['required_if:action,no_debit,debit_confirmed,history_confirm', 'nullable', 'string', 'max:4000'],
                'history_reconciliation_id' => [Rule::requiredIf(fn () => $this->input('action') === 'history_confirm' || ($this->input('action') === 'no_debit' && $this->input('proof_source') === 'provider_history')), 'nullable', 'integer', 'exists:supplier_order_history_reconciliations,id'],
                'history_timezone' => [Rule::requiredIf(fn () => $this->input('action') === 'history' && $this->route('fulfillment')?->currentPurchase?->supplier?->provider === 'toolorax'), 'nullable', 'timezone'],
                'debit_quantity' => ['required_if:action,debit_confirmed', 'nullable', 'integer', 'min:1', 'max:10000'],
                'debit_cost_micros' => ['required_if:action,debit_confirmed', 'nullable', 'integer', 'min:1', 'max:9000000000000000000'],
            ],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $provider = $this->input('provider');
            $selected = is_string($provider) ? SupplierProvider::tryFrom($provider) : null;
            if ($selected !== null && ! $selected->requiresCurrencyConfirmation() && $this->input('currency') !== $selected->defaultCurrency()) {
                $validator->errors()->add('currency', 'Mata uang harus sesuai provider.');
            }
            if ($this->input('provider') === 'buymail' && ($this->input('currency') !== 'IDR' || (int) $this->input('exchange_rate_idr') !== 1)) {
                $validator->errors()->add('currency', 'BuyMail menggunakan IDR dengan kurs 1.');
            }
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
