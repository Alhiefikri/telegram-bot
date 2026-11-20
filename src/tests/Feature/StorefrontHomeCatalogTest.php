<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontHomeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_and_search_intersect_without_limiting_the_family_picker(): void
    {
        $alpha = ProductFamily::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Family']);
        $beta = ProductFamily::factory()->create(['slug' => 'beta', 'name' => 'Beta Family']);
        $first = $this->plan('Paket Bulanan Alpha', $alpha, 5);
        $out = $this->plan('Paket Tahunan Alpha', $alpha, 0);
        $this->plan('Paket Bulanan Beta', $beta, 2);
        $this->plan('Bulanan Standalone', null, 2);

        $this->get('/?family=alpha&q=Bulanan')
            ->assertOk()
            ->assertSee('aria-current="true"', false)
            ->assertSee('value="alpha" selected', false)
            ->assertSee('value="beta"', false)
            ->assertSee('Paket Bulanan Alpha')
            ->assertSee(route('storefront.shop.checkout.product', $first), false)
            ->assertSee('href="'.url('/').'?q=bulanan"', false)
            ->assertDontSee('Paket Tahunan Alpha')
            ->assertDontSee('Paket Bulanan Beta')
            ->assertDontSee('Bulanan Standalone');

        $this->get('/?q=bulanan')->assertOk()->assertSee('Bulanan Standalone')->assertSee('Paket Bulanan Beta');
        $this->get('/?family=alpha&q=tidakada')->assertOk()->assertSee('Tidak ada Plan yang cocok')->assertSee('Hapus semua filter');
        $this->get('/?family=alpha&q=alpha+family')->assertOk()
            ->assertSee('Paket Bulanan Alpha')
            ->assertSee('Paket Tahunan Alpha')
            ->assertSee('Stok kosong')
            ->assertSee('#waitlist', false)
            ->assertDontSee(route('storefront.shop.checkout.product', $out), false);
    }

    public function test_private_missing_and_malformed_families_share_the_unselected_fallback(): void
    {
        $private = ProductFamily::factory()->create(['slug' => 'private-family', 'name' => 'Private Family']);
        $this->plan('Private Plan', $private, 2)->update(['is_active' => false]);
        $this->plan('Visible Standalone', null, 3);

        $baseline = null;
        foreach (['private-family', 'missing-family', '%3Csecret%3E', 'x%5B%5D'] as $slug) {
            $response = $this->get('/?family='.$slug.'&q=visible')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('content="noindex, follow"', false)
                ->assertSee('rel="canonical" href="'.url('/').'"', false)
                ->assertSee('Reset pilihan produk')
                ->assertSee('Visible Standalone')
                ->assertDontSee('Private Family')
                ->assertDontSee('Private Plan');
            $baseline ??= $response->getContent();
            $this->assertSame($baseline, $response->getContent());
        }

        $this->get('/?family[]=private-family&q[]=visible')->assertOk()->assertSee('Reset pilihan produk')->assertDontSee('Private Family');
    }

    public function test_home_cache_is_neutral_and_variants_do_not_read_or_poison_it(): void
    {
        Http::preventStrayRequests();
        $one = ProductFamily::factory()->create(['slug' => 'one']);
        $product = $this->plan('Plan One', $one, 3);
        $this->plan('Plan Two', ProductFamily::factory()->create(['slug' => 'two']), 2);
        $this->actingAs(Customer::factory()->create(['first_name' => 'Private Buyer Identity']), 'customer');

        $default = $this->get('/')->assertOk()->assertSee('Plan One')->assertSee('Plan Two')
            ->assertSee('content="index, follow"', false)->assertDontSee('Private Buyer Identity');
        $this->assertFalse($default->headers->has('Set-Cookie'));

        foreach (['/?family=one', '/?q=one', '/?family=&q=', '/?page=1'] as $path) {
            $variant = $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('content="noindex, follow"', false)->assertDontSee('Private Buyer Identity');
            $this->assertFalse($variant->headers->has('CDN-Cache-Control'));
            if (str_contains($path, '=one')) {
                $variant->assertSee('Plan One')->assertDontSee('Plan Two');
            }
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($default->getContent(), $this->get('/')->assertOk()->getContent());
        $this->assertEmpty(array_filter(DB::getQueryLog(), fn (array $entry) => str_contains($entry['query'], '"products"')));
        DB::disableQueryLog();

        $product->forceFill(['reserved_stock' => 3])->save();
        foreach (['/', '/?family=one'] as $path) {
            $this->get($path)->assertOk()->assertSee('Stok kosong')->assertDontSee(route('storefront.shop.checkout.product', $product), false);
        }
        Http::assertNothingSent();
    }

    public function test_picker_includes_families_beyond_the_first_page_with_bounded_queries(): void
    {
        DB::enableQueryLog();
        $this->plan('First Plan', ProductFamily::factory()->create(['slug' => 'first', 'sort_order' => 1]), 5);
        $count = function (): int {
            DB::flushQueryLog();
            $this->get('/?q=plan')->assertOk();

            return count(array_filter(DB::getQueryLog(), fn (array $entry) => preg_match('/from "(?:products|product_families)"/', $entry['query']) === 1));
        };
        $smallCatalogQueries = $count();
        for ($index = 2; $index <= 28; $index++) {
            $this->plan('Plan '.$index, ProductFamily::factory()->create(['slug' => 'family-'.$index, 'sort_order' => $index]), 5);
        }
        $this->assertSame($smallCatalogQueries, $count());
        DB::disableQueryLog();

        $this->get('/')->assertOk()->assertSee('value="family-28"', false)->assertDontSee('>Plan 28</a>', false)->assertSee('rel="next"', false);
        $this->get('/?page=2')->assertOk()->assertSee('>Plan 28</a>', false);
        $this->get('/?family=family-28')->assertOk()->assertSee('>Plan 28</a>', false);
    }

    private function plan(string $name, ?ProductFamily $family, int $stock): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'product_family_id' => $family?->id,
            'stock' => $stock,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
    }
}
