<?php

namespace App\Http\Requests\Admin;

use App\Services\Suppliers\SupplierExchangeRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class SupplierCurrencyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('adminUser')?->role?->canManageStoreOperations() === true;
    }

    public function rules(): array
    {
        return [
            'rates' => ['required', 'array'],
            'rates.*.exchange_rate_idr' => ['required', 'numeric', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/D', 'min:0.000001', 'max:1000000'],
            'rates.*.version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $rates = $this->input('rates');
            if (! is_array($rates) || $validator->errors()->has('rates')) {
                return;
            }
            $unknown = array_diff(array_keys($rates), SupplierExchangeRate::RATE_KEYS);
            if ($unknown !== []) {
                $validator->errors()->add('rates', 'Mata uang kurs tidak didukung.');

                return;
            }
            $missing = array_diff(SupplierExchangeRate::RATE_KEYS, array_keys($rates));
            if ($missing !== []) {
                $validator->errors()->add('rates', 'Isi kurs untuk USD/USDT dan VND.');
            }
            foreach (SupplierExchangeRate::RATE_KEYS as $rateKey) {
                $value = $rates[$rateKey]['exchange_rate_idr'] ?? null;
                if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                    continue;
                }
                if (! SupplierExchangeRate::isStorable($rateKey, $value)) {
                    $validator->errors()->add(
                        'rates.'.$rateKey.'.exchange_rate_idr',
                        SupplierExchangeRate::requirement($rateKey, $rateKey) ?? 'Kurs mata uang tidak wajar.',
                    );
                }
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            if (preg_match('/\Arates\.([A-Z]+)\.exchange_rate_idr\z/', $field, $match) === 1) {
                $errors[$field] = [SupplierExchangeRate::requirement($match[1], $match[1]) ?? 'Nilai kurs tidak valid.'];

                continue;
            }
            $safe = array_filter(array_intersect($messages, array_filter([
                'Isi kurs untuk USD/USDT dan VND.',
                'Mata uang kurs tidak didukung.',
                ...array_map(
                    static fn (string $rateKey): ?string => SupplierExchangeRate::requirement($rateKey, $rateKey),
                    SupplierExchangeRate::RATE_KEYS,
                ),
            ])));
            $errors[$field] = $safe === [] ? ['Nilai kurs tidak valid.'] : array_values(array_unique($safe));
        }
        throw new HttpResponseException(response()->json(['message' => 'Kurs mata uang tidak valid.', 'errors' => $errors], 422));
    }
}
