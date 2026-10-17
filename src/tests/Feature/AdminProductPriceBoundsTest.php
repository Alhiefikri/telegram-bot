<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminProductPriceBoundsTest extends TestCase
{
    use RefreshDatabase;

    public static function excessivePrices(): array
    {
        return [
            'retail IDR' => ['price_amount', (string) (intdiv(PHP_INT_MAX, 100) + 1)],
            'retail USD' => ['usdt_price_amount', '92233720368.548'],
            'reseller IDR' => ['reseller_price_amount', (string) (intdiv(PHP_INT_MAX, 100) + 1)],
            'reseller USD' => ['reseller_usdt_price_amount', '92233720368.548'],
            'tier IDR' => ['quantity_price_tiers.0.price_amount', (string) (intdiv(PHP_INT_MAX, 100) + 1)],
            'tier USD' => ['quantity_price_tiers.0.usdt_price_amount', '92233720368.548'],
        ];
    }

    #[DataProvider('excessivePrices')]
    public function test_admin_rejects_prices_above_the_quantity_100_representation_bound(string $field, string $value): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create(['price_amount' => 50000, 'usdt_price_amount' => 2000000]);
        $input = ['name' => 'Bounded Product', 'description' => 'Price boundary regression.',
            'price_amount' => '50000', 'usdt_price_amount' => '2',
            'quantity_price_tiers_present' => '1',
            'quantity_price_tiers' => [['minimum_quantity' => '2', 'price_amount' => '45000', 'usdt_price_amount' => '1.8']]];
        data_set($input, $field, $value);
        if (str_starts_with($field, 'quantity_price_tiers.')) {
            $input['price_amount'] = (string) PHP_INT_MAX;
            $input['usdt_price_amount'] = '9223372036854.775';
        }

        $this->postJson(route('admin.products.store'), $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson(route('admin.products.update', $product), $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(50000, $product->refresh()->price_amount);
        $this->assertSame(2000000, $product->usdt_price_amount);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_quantity_price_tiers', 0);
    }

    public function test_admin_accepts_the_largest_representable_prices_at_existing_form_precision(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $maximum = intdiv(PHP_INT_MAX, 100);
        $this->postJson(route('admin.products.store'), [
            'name' => 'Maximum Product', 'description' => 'Representable price.',
            'price_amount' => (string) $maximum, 'usdt_price_amount' => '92233720368.547',
            'reseller_price_amount' => (string) $maximum, 'reseller_usdt_price_amount' => '92233720368.547',
            'quantity_price_tiers_present' => '1',
            'quantity_price_tiers' => [['minimum_quantity' => '2', 'price_amount' => (string) ($maximum - 1), 'usdt_price_amount' => '92233720368.546']],
        ])->assertRedirect();
        $product = Product::where('name', 'Maximum Product')->firstOrFail();
        $this->assertSame($maximum, $product->price_amount);
        $this->assertSame(92233720368547000, $product->usdt_price_amount);
        $this->assertSame($maximum - 1, $product->quantityPriceTiers()->firstOrFail()->price_amount);
        $this->assertSame(92233720368546000, $product->quantityPriceTiers()->firstOrFail()->usdt_price_amount);
    }
}
