<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait StorefrontShopAssertions
{
    protected function assertRedirectsToSignedOrderShow(?string $location, Order $order): void
    {
        $this->assertIsString($location);
        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($location, PHP_URL_PATH));
        $this->assertStringContainsString('signature=', $location);
        $this->assertTrue(URL::hasValidSignature(Request::create($location, 'GET')));
    }

    protected function assertNoLocalCheckoutRowsCreated(string $context = ''): void
    {
        $this->assertSame(0, Customer::count(), $context);
        $this->assertSame(0, Cart::count(), $context);
        $this->assertSame(0, CartItem::count(), $context);
        $this->assertSame(0, Order::count(), $context);
        $this->assertSame(0, Payment::count(), $context);
        $this->assertSame(0, InventoryMovement::count(), $context);
    }
}
