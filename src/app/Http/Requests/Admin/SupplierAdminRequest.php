<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminSupplierRedirector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class SupplierAdminRequest extends FormRequest
{
    /**
     * Fixed, value-free messages so no request body (API key, enum, credentials)
     * can ever be echoed back through a validation message.
     *
     * @var array<string, string>
     */
    private const FIELD_MESSAGES = [
        'name' => 'Nama supplier wajib diisi, maksimal 120 karakter tanpa karakter kontrol.',
        'provider' => 'Provider supplier tidak dikenal.',
        'api_key' => 'API key wajib diisi (maksimal 2048 karakter, tanpa spasi).',
        'currency' => 'Mata uang harus USD atau USDT.',
        'currency_confirmed' => 'Konfirmasi mata uang supplier wajib dicentang untuk ARRS/Warzone.',
        'exchange_rate_idr' => 'Kurs harus berupa angka bulat Rp1 sampai Rp1.000.000 per unit.',
        'is_active' => 'Status aktif tidak valid.',
        'low_balance_threshold_micros' => 'Peringatan saldo rendah tidak valid.',
        'version' => 'Data sudah berubah di tempat lain. Muat ulang halaman lalu coba lagi.',
        'url' => 'Kolom URL tidak digunakan.',
        'base_url' => 'Kolom URL tidak digunakan.',
        'supplier_version' => 'Data supplier berubah. Muat ulang halaman lalu coba lagi.',
        'description' => 'Deskripsi draft maksimal 10.000 karakter.',
        'price_amount' => 'Harga jual harus angka Rp1 sampai Rp1.000.000.000.',
        'product_id' => 'Produk toko tidak ditemukan.',
        'equivalent_confirmed' => 'Centang konfirmasi produk ekuivalen dulu.',
        'priority' => 'Prioritas harus angka 0 sampai 10.000.',
        'mode' => 'Mode harga tidak dikenal.',
        'markup_basis_points' => 'Untung persen tidak valid.',
        'profit_idr' => 'Nominal untung tidak valid (0 sampai Rp1.000.000.000).',
        'minimum_profit_idr' => 'Batas untung minimum tidak valid (0 sampai Rp1.000.000.000).',
        'buffer_idr' => 'Buffer biaya tidak valid (0 sampai Rp1.000.000.000).',
        'rounding_idr' => 'Pilihan pembulatan tidak valid.',
        'catalog_item_id' => 'SKU supplier tidak ditemukan.',
        'action' => 'Tindakan tidak dikenal.',
        'supplier_order_id' => 'ID pesanan supplier tidak valid.',
        'credentials' => 'Kredensial manual tidak valid (maksimal 64.000 karakter).',
    ];

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
                'context' => ['nullable', 'string', Rule::in(AdminSupplierRedirector::CONTEXTS)],
                'context_id' => ['nullable', 'integer', 'min:0'],
                'url' => ['prohibited'], 'base_url' => ['prohibited'],
            ],
            'import' => [
                'supplier_version' => ['required', 'integer', 'min:0'],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'price_amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
                'context' => ['nullable', 'string', Rule::in(AdminSupplierRedirector::CONTEXTS)],
                'context_id' => ['nullable', 'integer', 'min:0'],
            ],
            'map' => [
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'supplier_version' => ['required', 'integer', 'min:0'],
                'version' => ['required', 'integer', 'min:0'],
                'equivalent_confirmed' => ['required', 'accepted'],
                'priority' => ['required', 'integer', 'min:0', 'max:10000'],
                'is_active' => ['required', 'boolean'],
                'context' => ['nullable', 'string', Rule::in(AdminSupplierRedirector::CONTEXTS)],
                'context_id' => ['nullable', 'integer', 'min:0'],
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
                'context' => ['nullable', 'string', Rule::in(AdminSupplierRedirector::CONTEXTS)],
                'context_id' => ['nullable', 'integer', 'min:0'],
            ],
            'operate' => [
                'action' => ['required', Rule::in(['retry', 'recover', 'manual'])],
                'supplier_order_id' => ['required_if:action,recover', 'nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_.:\-]+\z/'],
                'credentials' => ['required_if:action,manual', 'nullable', 'string', 'max:64000'],
                'context' => ['nullable', 'string', Rule::in(AdminSupplierRedirector::CONTEXTS)],
                'context_id' => ['nullable', 'integer', 'min:0'],
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
        // Fixed field messages avoid echoing validator values (e.g. an attacker-controlled enum).
        $errors = [];
        foreach ($validator->errors()->keys() as $field) {
            $errors[$field] = [self::FIELD_MESSAGES[$field] ?? 'Nilai bidang tidak valid.'];
        }

        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json(['message' => 'Data supplier tidak valid.', 'errors' => $errors], 422));
        }

        throw new HttpResponseException(app(AdminSupplierRedirector::class)->fromRequest($this, null, $errors));
    }
}
