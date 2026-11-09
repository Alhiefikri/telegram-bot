<?php

namespace Tests\Support;

use App\Storefront\StorefrontCheckoutChallenge;
use Illuminate\Http\Request;

final class FakeStorefrontCheckoutChallenge extends StorefrontCheckoutChallenge
{
    public function verify(Request $request): void {}
}
