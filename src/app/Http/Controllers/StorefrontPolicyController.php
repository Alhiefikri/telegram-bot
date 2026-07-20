<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontPresenter;
use Illuminate\Http\Response;

class StorefrontPolicyController extends Controller
{
    public function privacy(StorefrontPresenter $storefront): Response
    {
        return $this->render($storefront, 'privacy');
    }

    public function terms(StorefrontPresenter $storefront): Response
    {
        return $this->render($storefront, 'terms');
    }

    public function refund(StorefrontPresenter $storefront): Response
    {
        return $this->render($storefront, 'refund');
    }

    public function recovery(StorefrontPresenter $storefront): Response
    {
        return $this->render($storefront, 'recovery');
    }

    private function render(StorefrontPresenter $storefront, string $key): Response
    {
        $brand = $storefront->brand();
        $policy = config('storefront.policies.'.$key);

        return response()->view('storefront.policy', [
            'storefront' => $storefront,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'shopUrl' => $storefront->catalogUrl(),
            'pageTitle' => $policy['title'].' | '.$brand,
            'pageDescription' => $policy['description'],
            'canonicalUrl' => route('storefront.policies.'.$key),
            'policy' => $policy,
        ]);
    }
}
