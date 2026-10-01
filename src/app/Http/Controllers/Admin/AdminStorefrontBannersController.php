<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStorefrontBannersRequest;
use App\Storefront\StorefrontBanners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdminStorefrontBannersController extends Controller
{
    public function __invoke(UpdateStorefrontBannersRequest $request, StorefrontBanners $banners): RedirectResponse
    {
        $data = $request->validated();
        try {
            $banners->save($data['banners'], (int) $data['version'], $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return redirect()->route('admin.dashboard', ['tab' => 'produk'])->withFragment('admin-storefront-banners')
                ->withErrors($exception->errors(), 'storefrontBanners')->withInput();
        }

        return redirect()->route('admin.dashboard', ['tab' => 'produk'])->withFragment('admin-storefront-banners')
            ->with('status', 'Banner beranda berhasil diperbarui.');
    }
}
