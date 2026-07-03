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

        return view('storefront.login-guide', [
            'brand' => $brand,
            'copy' => config('storefront'),
            'pageTitle' => 'Panduan Login Akun | '.$brand,
            'pageDescription' => 'Panduan masuk dan mengamankan akun digital Lhie Store memakai Outlook, iCloud, dan Google Authenticator.',
            'canonicalUrl' => route('storefront.login-guide'),
            'supportDestination' => $this->storefront->supportDestination(),
        ]);
    }
}
