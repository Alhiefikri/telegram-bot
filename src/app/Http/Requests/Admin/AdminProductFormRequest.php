<?php

namespace App\Http\Requests\Admin;

use App\Catalog\LocalizedProductCopy;
use App\Models\Product;
use App\Services\Admin\AdminProductQuantityPricing;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Telegram\TelegramProductContent;
use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

abstract class AdminProductFormRequest extends FormRequest
{
    /** @var list<string> */
    private const MERCHANDISING_FIELDS = [
        'product_type',
        'access_duration',
        'region',
        'account_type',
        'device_limit',
        'included_items',
        'limitations',
        'fulfillment_expectation',
        'warranty_policy',
        'support_policy',
        'featured_rank',
    ];

    /** Locale variant columns; base `description`/`warranty_policy` keep their own handling. */
    private const LOCALIZED_COPY_FIELDS = ['description_ms', 'description_en', 'warranty_policy_ms', 'warranty_policy_en'];

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['icon', 'usage_guide_title', 'usage_guide_url', ...self::LOCALIZED_COPY_FIELDS] as $field) {
            if ($this->exists($field)) {
                $normalized[$field] = $this->nullableTrimmedString($field);
            }
        }
        foreach (self::MERCHANDISING_FIELDS as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $normalized[$field] = in_array($field, ['included_items', 'limitations'], true)
                ? $this->normalizedLineList($field)
                : $this->nullableTrimmedString($field);
        }
        $this->merge($normalized);

        if ($this->has('quantity_price_tiers_present')) {
            $tiers = $this->input('quantity_price_tiers', []);
            $normalized['quantity_price_tiers'] = is_array($tiers) ? array_values($tiers) : $tiers;
        }

        $this->merge($normalized);

        foreach (['usdt_price_amount', 'reseller_usdt_price_amount'] as $field) {
            $value = trim((string) $this->input($field, ''));
            if ($value === '') {
                continue;
            }

            if (str_contains($value, '.')) {
                [$whole, $fraction] = explode('.', $value, 2);

                if ($fraction !== '') {
                    $fraction = rtrim($fraction, '0');
                    $value = $fraction === '' ? $whole : $whole.'.'.$fraction;
                }
            }

            if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $m)) {
                $this->merge([$field => ((int) $m[1] * 1_000_000) + (int) str_pad($m[2] ?? '', 6, '0')]);
            }
        }
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $this->validateResellerPriceInput($validator);

            if (! $this->hasQuantityPriceTiersInput() || $this->hasTierFieldErrors($validator)) {
                $this->validateProductDetailBudget($validator);

                return;
            }

            try {
                app(AdminProductQuantityPricing::class)->validateInput(
                    $this->input('quantity_price_tiers', []),
                    (int) $this->input('price_amount'),
                    $this->input('usdt_price_amount') === null ? null : (int) $this->input('usdt_price_amount'),
                );
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('quantity_price_tiers', $this->tierValidationMessage($exception));
            }

            $this->validateProductDetailBudget($validator);
        });
    }

    /**
     * One locale's Product Details plus Replacement / Refund Policy are delivered
     * as one Telegram Product Detail message, so every locale variant pair must
     * respect the shared unit budget. The base copy stays uncapped here: it
     * predates this form and legacy pairs above the budget must remain saveable;
     * the `/admin` editor already refuses over-budget base copy.
     */
    private function validateProductDetailBudget(ValidatorContract $validator): void
    {
        $content = app(TelegramProductContent::class);

        foreach (LocalizedProductCopy::LOCALES as $locale) {
            if ($locale === LocalizedProductCopy::BASE_LOCALE) {
                continue;
            }
            $pairs = [];
            foreach (LocalizedProductCopy::FIELDS as $field) {
                $column = LocalizedProductCopy::column($field, $locale);
                $pairs[$column] = (string) $this->input($column, '');
            }
            if ($content->units(implode('', $pairs)) <= TelegramProductContent::MAX_PRODUCT_DETAIL_UNITS) {
                continue;
            }
            $validator->errors()->add(
                LocalizedProductCopy::column('description', $locale),
                'Total Product Details + Refund Policy '.LocalizedProductCopy::LOCALE_LABELS[$locale].' maksimal '.TelegramProductContent::MAX_PRODUCT_DETAIL_UNITS.' unit UTF-16 (emoji dapat dihitung dua unit) agar muat dalam satu pesan Telegram.',
            );
        }
    }

    public function hasQuantityPriceTiersInput(): bool
    {
        return $this->boolean('quantity_price_tiers_present') || $this->has('quantity_price_tiers');
    }

    /**
     * @return list<array{minimum_quantity:?int, price_amount:?int, usdt_price_amount:?int}>
     */
    public function quantityPriceTiers(): array
    {
        return app(AdminProductQuantityPricing::class)->normalizeInput($this->input('quantity_price_tiers', []));
    }

    private function hasTierFieldErrors(ValidatorContract $validator): bool
    {
        foreach (array_keys($validator->errors()->toArray()) as $attribute) {
            if ($attribute === 'price_amount'
                || $attribute === 'usdt_price_amount'
                || $attribute === 'quantity_price_tiers'
                || str_starts_with($attribute, 'quantity_price_tiers.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A reseller dollar override is only valid while the Product keeps a base
     * dollar price. The check uses the effective post-write values so a partial
     * price save cannot leave an override without an eligible dollar price.
     */
    private function validateResellerPriceInput(ValidatorContract $validator): void
    {
        if ($this->hasResellerFieldErrors($validator)) {
            return;
        }

        try {
            app(ProductQuantityPricing::class)->validateResellerOverrides(
                $this->effectivePriceAmount('reseller_price_amount'),
                $this->effectivePriceAmount('usdt_price_amount'),
                $this->effectivePriceAmount('reseller_usdt_price_amount'),
            );
        } catch (InvalidArgumentException $exception) {
            $attribute = str_contains($exception->getMessage(), 'reseller IDR price')
                ? 'reseller_price_amount'
                : 'reseller_usdt_price_amount';

            $validator->errors()->add($attribute, $this->resellerValidationMessage($exception));
        }
    }

    private function hasResellerFieldErrors(ValidatorContract $validator): bool
    {
        foreach (array_keys($validator->errors()->toArray()) as $attribute) {
            if ($attribute === 'reseller_price_amount'
                || $attribute === 'reseller_usdt_price_amount'
                || $attribute === 'usdt_price_amount') {
                return true;
            }
        }

        return false;
    }

    private function resellerValidationMessage(InvalidArgumentException $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'reseller IDR price') => 'Harga reseller IDR harus lebih besar dari 0.',
            str_contains($message, 'must be positive') => 'Harga reseller USDT harus lebih besar dari 0.',
            default => 'Harga reseller USDT hanya berlaku jika produk memiliki harga USDT dasar. Isi Harga USDT atau kosongkan harga reseller USDT.',
        };
    }

    /**
     * Resolve a price field the way the write will: the submitted value, or the
     * persisted one when a partial section save omits it.
     */
    private function effectivePriceAmount(string $field): ?int
    {
        if ($this->exists($field)) {
            $value = $this->input($field);

            return $value === null || $value === '' ? null : (int) $value;
        }

        $product = $this->route('product');

        if (! $product instanceof Product || $product->getAttribute($field) === null) {
            return null;
        }

        return (int) $product->getAttribute($field);
    }

    private function tierValidationMessage(InvalidArgumentException $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'more than five') => 'Jadwal harga maksimal berisi 5 tingkat.',
            str_contains($message, 'minimum quantities') => 'Minimum quantity harus unik dan berurutan naik.',
            str_contains($message, 'IDR prices') => 'Harga IDR setiap tingkat harus positif dan turun dari harga sebelumnya.',
            str_contains($message, 'absent when') => 'Harga USDT tingkat harus dikosongkan jika produk tidak memiliki harga USDT dasar.',
            str_contains($message, 'USDT prices') => 'Harga USDT setiap tingkat wajib lengkap, positif, dan turun dari harga sebelumnya.',
            default => 'Jadwal harga bertingkat tidak valid.',
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function productRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:32'],
            'price_amount' => ['required', 'integer', 'min:0'],
            'usdt_price_amount' => ['nullable', 'integer', 'min:0'],
            'reseller_price_amount' => ['nullable', 'integer', 'min:1'],
            'reseller_usdt_price_amount' => ['nullable', 'integer', 'min:1'],
            'quantity_price_tiers_present' => ['sometimes', 'boolean'],
            'quantity_price_tiers' => ['sometimes', 'array', 'max:5'],
            'quantity_price_tiers.*' => ['array'],
            'quantity_price_tiers.*.minimum_quantity' => ['required', 'integer', 'between:2,20'],
            'quantity_price_tiers.*.price_amount' => ['required', 'integer', 'min:1'],
            'quantity_price_tiers.*.usdt_price_amount' => ['nullable', 'regex:/^\d+(?:\.\d{1,3})?$/', 'gt:0'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'product_family_id' => ['nullable', 'integer', Rule::exists('product_families', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string'],
            'description_ms' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'product_type' => ['nullable', 'string', 'max:100'],
            'access_duration' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:120'],
            'account_type' => ['nullable', 'string', 'max:100'],
            'device_limit' => ['nullable', 'string', 'max:100'],
            'included_items' => [
                'nullable',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validateLineList($value, $fail, 'Item yang termasuk');
                },
            ],
            'limitations' => [
                'nullable',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validateLineList($value, $fail, 'Batasan');
                },
            ],
            'fulfillment_expectation' => ['nullable', 'string'],
            'warranty_policy' => ['nullable', 'string'],
            'warranty_policy_ms' => ['nullable', 'string'],
            'warranty_policy_en' => ['nullable', 'string'],
            'support_policy' => ['nullable', 'string'],
            'featured_rank' => ['nullable', 'integer', 'min:1', 'max:999'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
            'usage_guide_title' => ['nullable', 'string', 'max:160', 'required_with:usage_guide_url'],
            'usage_guide_url' => [
                'nullable',
                'string',
                'max:2048',
                'required_with:usage_guide_title',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->isSafeUsageGuideUrl($value)) {
                        $fail('URL panduan harus berupa URL HTTP/HTTPS atau path lokal yang diawali satu garis miring.');
                    }
                },
            ],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'telegram_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'telegram_channel_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function merchandisingAttributes(): array
    {
        $validated = $this->validated();
        $attributes = [];

        foreach (self::MERCHANDISING_FIELDS as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama produk wajib diisi.',
            'price_amount.required' => 'Harga wajib diisi.',
            'price_amount.integer' => 'Harga harus berupa angka tanpa pemisah.',
            'usdt_price_amount.integer' => 'Harga USDT harus berupa angka dengan maksimal 3 desimal.',
            'reseller_price_amount.integer' => 'Harga reseller IDR harus berupa angka bulat.',
            'reseller_price_amount.min' => 'Harga reseller IDR harus lebih besar dari 0.',
            'reseller_usdt_price_amount.integer' => 'Harga reseller USDT harus berupa angka dengan maksimal 3 desimal.',
            'reseller_usdt_price_amount.min' => 'Harga reseller USDT harus lebih besar dari 0.',
            'quantity_price_tiers.array' => 'Jadwal harga bertingkat tidak valid.',
            'quantity_price_tiers.max' => 'Jadwal harga bertingkat maksimal berisi 5 tingkat.',
            'quantity_price_tiers.*.minimum_quantity.required' => 'Minimum quantity wajib diisi.',
            'quantity_price_tiers.*.minimum_quantity.integer' => 'Minimum quantity harus berupa angka bulat.',
            'quantity_price_tiers.*.minimum_quantity.between' => 'Minimum quantity harus antara 2 dan 20.',
            'quantity_price_tiers.*.price_amount.required' => 'Harga IDR tingkat wajib diisi.',
            'quantity_price_tiers.*.price_amount.integer' => 'Harga IDR tingkat harus berupa angka bulat.',
            'quantity_price_tiers.*.price_amount.min' => 'Harga IDR tingkat harus lebih besar dari 0.',
            'quantity_price_tiers.*.usdt_price_amount.regex' => 'Harga USDT tingkat maksimal memiliki 3 angka desimal.',
            'quantity_price_tiers.*.usdt_price_amount.gt' => 'Harga USDT tingkat harus lebih besar dari 0.',
            'description.required' => 'Deskripsi singkat wajib diisi.',
            'icon.max' => 'Ikon katalog maksimal 32 karakter.',
            'product_type.max' => 'Tipe produk maksimal 100 karakter.',
            'access_duration.max' => 'Durasi akses maksimal 100 karakter.',
            'region.max' => 'Region atau negara maksimal 120 karakter.',
            'account_type.max' => 'Tipe akun maksimal 100 karakter.',
            'device_limit.max' => 'Batas perangkat atau seat maksimal 100 karakter.',
            'included_items.max' => 'Daftar yang termasuk maksimal 2000 karakter.',
            'limitations.max' => 'Daftar batasan maksimal 2000 karakter.',
            'featured_rank.integer' => 'Urutan pilihan utama harus berupa angka.',
            'featured_rank.min' => 'Urutan pilihan utama minimal 1.',
            'featured_rank.max' => 'Urutan pilihan utama maksimal 999.',
            'delivery_note.max' => 'Catatan pengiriman rahasia maksimal 2000 karakter.',
            'usage_guide_title.required_with' => 'Judul panduan wajib diisi jika URL panduan diisi.',
            'usage_guide_title.max' => 'Judul panduan maksimal 160 karakter.',
            'usage_guide_url.required_with' => 'URL panduan wajib diisi jika judul panduan diisi.',
            'usage_guide_url.max' => 'URL panduan maksimal 2048 karakter.',
            'category_id.exists' => 'Kategori tidak valid.',
            'image.uploaded' => 'Upload gambar gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
            'image.image' => 'File harus berupa gambar yang valid.',
            'image.mimes' => 'Gambar produk harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar produk maksimal 5 MB.',
            'image.dimensions' => 'Dimensi gambar produk maksimal 6000 x 6000 piksel.',
        ];
    }

    private function nullableTrimmedString(string $key): mixed
    {
        $value = $this->input($key, '');

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizedLineList(string $key): mixed
    {
        $value = $this->input($key, '');

        if (! is_string($value)) {
            return $value;
        }

        $lines = preg_split('/\R/u', trim($value)) ?: [];
        $lines = array_map(static fn (string $line): string => trim($line), $lines);
        $lines = array_filter($lines, static fn (string $line): bool => $line !== '');

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function validateLineList(mixed $value, Closure $fail, string $label): void
    {
        if (! is_string($value)) {
            return;
        }

        $lines = preg_split('/\R/u', $value) ?: [];
        if (count($lines) > 20) {
            $fail($label.' maksimal 20 baris.');
        }

        foreach ($lines as $line) {
            if (mb_strlen($line, 'UTF-8') > 160) {
                $fail($label.' maksimal 160 karakter per baris.');
                break;
            }
        }
    }

    private function isSafeUsageGuideUrl(string $value): bool
    {
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
