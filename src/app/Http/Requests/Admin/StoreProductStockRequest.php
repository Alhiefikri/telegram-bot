<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class StoreProductStockRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        return Gate::allows('adjustStock', $this->product());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'stock_items' => ['required', 'string', 'max:5000'],
            'operation_key' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stock_items.required' => 'Isi minimal satu baris stok.',
            'stock_items.max' => 'Catatan stok maksimal 5000 karakter.',
        ];
    }

    /**
     * @return array<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('stock_items') && $this->stockItems() === []) {
                    $validator->errors()->add('stock_items', 'Isi minimal satu baris stok.');
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    public function stockItems(): array
    {
        return collect(preg_split('/\R/u', (string) $this->input('stock_items', '')) ?: [])
            ->map(fn (string $row): string => trim($row))
            ->filter()
            ->values()
            ->all();
    }

    public function operationKey(): ?string
    {
        $value = $this->input('operation_key');

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return app(AdminProductDashboardFormProtocol::class)->stockValidationErrors($this, $this->product(), $validator->errors()->toArray());
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
