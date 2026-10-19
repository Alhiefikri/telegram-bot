<?php

namespace App\Http\Requests\Admin;

use App\Enums\SupplierProvider;
use App\Services\Suppliers\SupplierExchangeRate;
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
                'currency' => ['required', Rule::in(['USD', 'USDT', 'IDR', 'VND'])],
                'currency_confirmed' => ['required', 'boolean'],
                'exchange_rate_idr' => ['required', 'numeric', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/D', 'min:0.000001', 'max:1000000'],
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
            if (in_array($this->input('provider'), ['arrs', 'warzone'], true) && ! $this->boolean('currency_confirmed')) {
                $validator->errors()->add('currency_confirmed', 'Konfirmasi mata uang supplier diperlukan.');
            }
            // A rate outside the plausible band of the connection currency is a
            // configuration error, not a price: reject it however the request arrived.
            $rate = $this->input('exchange_rate_idr');
            if ($selected !== null && ! $validator->errors()->has('exchange_rate_idr')
                && (is_string($rate) || is_int($rate) || is_float($rate))
                && ! SupplierExchangeRate::isPlausible($selected->defaultCurrency(), $rate)) {
                $validator->errors()->add('exchange_rate_idr', (string) SupplierExchangeRate::requirement($selected->label(), $selected->defaultCurrency()));
            }
        }];
    }

    /** Explanations written by this class. Everything else is replaced, so a request value is never echoed. */
    private function safeMessages(string $field): array
    {
        return match ($field) {
            'currency' => ['Mata uang harus sesuai provider.'],
            'currency_confirmed' => ['Konfirmasi mata uang supplier diperlukan.'],
            'exchange_rate_idr' => array_values(array_filter(array_map(
                fn (SupplierProvider $provider): ?string => SupplierExchangeRate::requirement($provider->label(), $provider->defaultCurrency()),
                SupplierProvider::cases(),
            ))),
            default => [],
        };
    }

    protected function failedValidation(Validator $validator): void
    {
        // Never flash a request body containing an API key or delivery credentials.
        // Generic field errors also avoid validator values (e.g. an attacker-controlled enum).
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $safe = array_values(array_unique(array_intersect($messages, $this->safeMessages($field))));
            $errors[$field] = $safe === [] ? ['Nilai bidang tidak valid.'] : $safe;
        }
        throw new HttpResponseException(response()->json(['message' => 'Data supplier tidak valid.', 'errors' => $errors], 422));
    }
}
