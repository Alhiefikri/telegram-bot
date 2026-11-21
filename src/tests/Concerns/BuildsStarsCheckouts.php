<?php

namespace Tests\Concerns;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Checkout\TelegramStarsCheckoutIntent;
use App\Services\Checkout\TelegramStarsCheckoutPaymentPreparation;
use App\Services\Checkout\TelegramStarsConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

trait BuildsStarsCheckouts
{
    private function enableStars(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        DB::table('stars_pricing_policies')->update(['idr_per_usd' => 18_300, 'reserve_bps' => 1_500, 'issuance_enabled' => true, 'version' => 1]);
    }

    private function starsProduct(int $stock = 1, bool $withTier = false): Product
    {
        $factory = Product::factory()->state(['stock' => $stock]);
        if ($withTier) {
            $factory = $factory->withQuantityPriceTiers([['minimum_quantity' => 2, 'price_amount' => 5_000]]);
        }
        $product = $factory->create();
        for ($i = 0; $i < $stock; $i++) {
            $product->stockItems()->create(['secret' => "fixture{$i}@example.com | password"]);
        }

        return $product;
    }

    private function starsCart(Customer $customer, Product $product, int $quantity = 1): Cart
    {
        $cart = Cart::factory()->for($customer)->create();
        $cart->cartItems()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }

    private function starsIntent(Customer $customer, Cart|Product $selection, string $key = 'stars-quote', ?int $quantity = null): TelegramStarsCheckoutIntent
    {
        $confirmation = new TelegramStarsConfirmation($customer->id, $customer->telegram_id, $customer->telegram_id, 'private', 'revision-a', $key);
        $quote = app(TelegramStarsCheckoutPaymentPreparation::class)->preview($selection, $confirmation, $quantity);

        return new TelegramStarsCheckoutIntent($quote, $confirmation);
    }
}
