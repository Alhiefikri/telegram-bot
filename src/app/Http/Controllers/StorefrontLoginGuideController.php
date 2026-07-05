<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontPresenter;
use Illuminate\Contracts\View\View;

class StorefrontLoginGuideController extends Controller
{
    public function __construct(private readonly StorefrontPresenter $storefront) {}

    public function show(): View
    {
        $brand = $this->storefront->brand();
        $guide = config('storefront.login_guide');
        $guide['images'] = array_map(static fn (string $path): string => asset($path), $guide['images']);

        return view('storefront.login-guide', [
            'brand' => $brand,
            'copy' => config('storefront'),
            'guide' => $guide,
            'pageTitle' => 'Panduan Login Akun | '.$brand,
            'pageDescription' => 'Panduan masuk dan mengamankan akun digital Lhie Store memakai Outlook, iCloud, dan Google Authenticator.',
            'canonicalUrl' => route('storefront.login-guide'),
            'supportDestination' => $this->storefront->supportDestination(),
        ]);
    }
}
