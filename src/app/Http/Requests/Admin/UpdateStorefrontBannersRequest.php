<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RedirectsWithValidationErrors;
use App\Storefront\StorefrontBanners;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class UpdateStorefrontBannersRequest extends FormRequest
{
    use RedirectsWithValidationErrors;

    public function authorize(): bool
    {
        return (bool) $this->attributes->get('adminUser')?->role?->canMutateProducts();
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'banners' => ['required', 'array', 'list', 'size:'.StorefrontBanners::MAX_BANNERS],
            'banners.*' => ['array:title,link,remove,image'],
            'banners.*.title' => ['nullable', 'string', 'max:160'],
            'banners.*.link' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! str_starts_with($value, '/') || str_starts_with($value, '//') || preg_match('/[\\\\\x00-\x20]/', $value)) {
                    $fail('Gunakan tautan halaman toko seperti /katalog atau /pilihan/chatgpt.');
                }
            }],
            'banners.*.remove' => ['sometimes', 'boolean'],
            'banners.*.image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    protected function validationErrorRedirect(Validator $validator): RedirectResponse
    {
        return redirect()->route('admin.dashboard', ['tab' => 'produk'])
            ->withFragment('admin-storefront-banners')->withErrors($validator, 'storefrontBanners')->withInput();
    }
}
