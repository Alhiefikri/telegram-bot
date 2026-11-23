<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontHomeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_filter_renders_a_card_per_plan_with_cover_prices_and_details(): void
    {
        $family = ProductFamily::factory()->create([
            'slug' => 'gemini',
            'image_path' => 'product-families/gemini-cover.webp',
            'sidebar_icon_path' => 'product-family-icons/gemini.webp',
        ]);
        $first = $this->plan('Gemini Bulanan', $family, 2);
        $first->update(['image_path' => 'products/monthly.webp', 'price_amount' => 25000, 'usdt_price_amount' => 1500000]);
        $second = $this->plan('Gemini Tahunan', $family, 0);
        $third = $this->plan('Gemini 18 Bulan', $family, 2);

        $html = $this->get('/?family=gemini')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        $this->assertSame(3, $xpath->query('//*[@id="home-catalog-plans"]/article[@data-home-plan]')->length);
        $card = fn (Product $plan) => '//*[@id="product-'.$plan->id.'"]';
        $this->assertSame('/storage/products/monthly.webp', $xpath->evaluate('string('.$card($first).'//img/@src)'));
        $this->assertStringContainsString('Rp25.000', $xpath->evaluate('string('.$card($first).')'));
        $this->assertStringContainsString('1.5 USDT', $xpath->evaluate('string('.$card($first).')'));
        $this->assertStringNotContainsString('USDT', $xpath->evaluate('string('.$card($second).')'));

        $this->assertSame('/storage/product-family-icons/gemini.webp', $xpath->evaluate('string(//*[@aria-labelledby="catalog-family-title"]//a[contains(@href, "family=gemini")]//img/@src)'));
        $this->assertSame('/storage/product-families/gemini-cover.webp', $xpath->evaluate('string('.$card($second).'//img/@src)'));
        foreach ([$first, $second, $third] as $plan) {
            $details = $xpath->evaluate('string('.$card($plan).'//a[@aria-label="Lihat detail '.$plan->name.'"]/@href)');
            $this->assertStringStartsWith(route('storefront.products.show', $plan).'?from=', $details);
            $this->assertSame(0, $xpath->query($card($plan).'//img[contains(@src, "product-family-icons")]')->length);
        }
    }

    public function test_bundled_family_icons_fill_empty_slots_without_overwriting_admin_icons(): void
    {
        $duolingo = ProductFamily::factory()->create(['slug' => 'duolingo']);
        $chatgpt = ProductFamily::factory()->create([
            'slug' => 'chatgpt',
            'sidebar_icon_path' => 'product-family-icons/admin-chatgpt.webp',
        ]);
        $other = ProductFamily::factory()->create(['slug' => 'another-app', 'icon' => '🎨']);
        foreach ([$duolingo, $chatgpt, $other] as $family) {
            $this->plan('Test plan '.$family->slug, $family, 1);
        }

        $migration = require database_path('migrations/2026_11_22_000002_seed_storefront_family_filter_icons.php');
        $migration->up();
        $bundledIcon = '/storefront/family-icons/duolingo.webp';
        $upload = 'product-family-icons/admin-chatgpt.webp';

        $this->assertSame($bundledIcon, $duolingo->refresh()->sidebar_icon_path);
        $this->assertFileExists(public_path(ltrim($bundledIcon, '/')));
        $this->assertSame($upload, $chatgpt->refresh()->sidebar_icon_path);
        $this->assertNull($other->refresh()->sidebar_icon_path);
        $this->get('/?family=duolingo')
            ->assertOk()
            ->assertSee('src="'.$bundledIcon.'"', false)
            ->assertSee('src="/storage/'.$upload.'"', false)
            ->assertSee('🎨');

        $duolingo->update(['slug' => 'super-duolingo']);
        $migration->down();

        $this->assertNull($duolingo->refresh()->sidebar_icon_path);
        $this->assertSame($upload, $chatgpt->refresh()->sidebar_icon_path);
    }

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

    public function test_home_sort_uses_available_stock_and_todays_paid_units(): void
    {
        $this->travelTo(now()->setTime(14, 0));
        $alpha = $this->plan('Alpha', null, 5);
        $alpha->update(['price_amount' => 12000]);
        $zulu = $this->plan('Zulu', null, 4);
        $zulu->update(['price_amount' => 24000]);
        $reserved = $this->plan('Middle', null, 2);
        $reserved->forceFill(['reserved_stock' => 2, 'price_amount' => 6000])->save();
        $this->recordSale($alpha, 20, now()->subDay());
        $this->recordSale($alpha, 1, now()->startOfDay());
        $this->recordSale($alpha, 20, now(), 'pending');
        $this->recordSale($zulu, 3, now());
        $this->recordSale($reserved, 10, now());

        $this->get('/')->assertOk()->assertSeeInOrder(['>Zulu</a>', '>Alpha</a>', '>Middle</a>'], false);
        $this->get('/?sort=price_asc')->assertOk()->assertSeeInOrder(['>Middle</a>', '>Alpha</a>', '>Zulu</a>'], false);
        $this->get('/?sort=price_desc')->assertOk()->assertSeeInOrder(['>Zulu</a>', '>Alpha</a>', '>Middle</a>'], false);
        $this->get('/?sort=name_asc')->assertOk()->assertSeeInOrder(['>Alpha</a>', '>Middle</a>', '>Zulu</a>'], false);
        $this->get('/?sort[]=invalid&in_stock[]=1')->assertOk()
            ->assertSeeInOrder(['>Zulu</a>', '>Alpha</a>', '>Middle</a>'], false);

        $this->get('/?sort=name_asc&in_stock=1', ['X-Storefront-Catalog' => '1'])->assertOk()
            ->assertHeader('X-Storefront-Catalog', '1')
            ->assertSee('2 produk')->assertSeeInOrder(['>Alpha</a>', '>Zulu</a>'], false)
            ->assertDontSee('>Middle</a>', false);
        $this->get('/?q=middle&in_stock=1')->assertOk()
            ->assertSee('0 produk')->assertSee('Hapus semua filter');

        $this->travelTo(now()->endOfDay()->subMinute());
        $this->get('/')->assertOk()->assertSeeInOrder(['>Zulu</a>', '>Alpha</a>'], false);
        $this->travelTo(now()->addDay()->startOfDay());
        $this->get('/')->assertOk()->assertSeeInOrder(['>Alpha</a>', '>Zulu</a>'], false);
    }

    public function test_home_filters_are_applied_before_pagination_and_survive_navigation(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'sorted']);
        $this->plan('Plan 00 sold out', $family, 0);
        foreach (range(25, 1) as $number) {
            $this->plan(sprintf('Plan %02d', $number), $family, 1);
        }
        $query = 'family=sorted&q=plan&sort=name_asc&in_stock=1';
        $next = 'family=sorted&q=plan&page=2&sort=name_asc&in_stock=1';
        $this->get('/?'.$query)->assertOk()
            ->assertSee('25 produk')
            ->assertSee('href="'.url('/').'?'.str_replace('&', '&amp;', $next).'#products"', false)
            ->assertDontSee('Plan 00 sold out')
            ->assertDontSee('>Plan 25</a>', false);
        $this->get('/?'.$next)->assertOk()
            ->assertSee('25 produk')
            ->assertSee('>Plan 25</a>', false)
            ->assertSee('from='.rawurlencode(url('/').'?'.$next.'#product-'), false)
            ->assertDontSee('>Plan 01</a>', false);
        $this->get(route('storefront.locale.catalog', ['locale' => 'en', 'return' => '/?'.$next]))
            ->assertRedirect('/?'.$next);
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

    public function test_warm_home_cache_tracks_maintenance_without_hiding_details_or_waitlist(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'maintenance-plans']);
        $ready = $this->plan('Ready Plan', $family, 3);
        $soldOut = $this->plan('Sold Out Plan', $family, 0);
        $actor = User::factory()->create();
        $maintenance = app(CheckoutMaintenance::class);
        $checkout = route('storefront.shop.checkout.product', $ready);
        $details = route('storefront.products.show', $ready);
        $waitlist = route('storefront.products.show', $soldOut);

        $this->get('/')->assertOk()->assertSee($checkout, false);
        $this->get('/')->assertOk()->assertSee($checkout, false);
        $active = $maintenance->change(true, 'Fixture maintenance notice', $maintenance->current()->version, $actor, null);
        foreach (['/', '/', '/?family=maintenance-plans'] as $path) {
            $this->get($path)->assertOk()->assertSee('Fixture maintenance notice')
                ->assertSee('Lihat detail')->assertSee($details, false)
                ->assertSee($waitlist, false)->assertSee('#waitlist', false)
                ->assertDontSee($checkout, false)->assertDontSee('Beli sekarang');
        }
        $this->get($details)->assertOk()->assertSee('Ready Plan');
        $this->get($waitlist)->assertOk()->assertSee('Dapatkan kabar saat stok tersedia');
        $this->get('/?family=maintenance-plans', ['X-Storefront-Catalog' => '1'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Fixture maintenance notice')->assertSee($details, false)
            ->assertSee('#waitlist', false)->assertDontSee($checkout, false);

        $maintenance->change(false, null, $active->newState->version, $actor, null);
        foreach (['/', '/?family=maintenance-plans'] as $path) {
            $this->get($path)->assertOk()->assertSee($checkout, false)
                ->assertSee('Beli sekarang')->assertDontSee('Fixture maintenance notice');
        }
    }

    public function test_family_fragment_keeps_the_catalog_contract_with_constant_select_count(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'alpha']);
        $this->plan('Alpha Plan', $family, 2);
        $readFragment = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->get('/?family=alpha&q=plan', ['X-Storefront-Catalog' => '1'])
                    ->assertOk()
                    ->assertHeader('X-Storefront-Catalog', '1')
                    ->assertHeader('Vary', 'X-Storefront-Catalog')
                    ->assertHeader('Cache-Control', 'no-store, private')
                    ->assertSee('data-catalog-fragment', false)
                    ->assertSee('data-robots="noindex, follow"', false)
                    ->assertSee('data-catalog-maintenance', false)
                    ->assertSee('return=%2F%3Ffamily%3Dalpha%26q%3Dplan', false)
                    ->assertSee('Alpha Plan')
                    ->assertDontSee('Unrelated Plan')
                    ->assertDontSee('<html', false)
                    ->assertDontSee('csrf-token', false);

                return count(array_filter(DB::getQueryLog(), fn (array $entry) => str_starts_with(strtolower(ltrim($entry['query'])), 'select')));
            } finally {
                DB::disableQueryLog();
            }
        };

        $readFragment(); // Warm request-independent lookups before comparing catalog sizes.
        $oneFamilySelects = $readFragment();
        foreach (range(1, 25) as $index) {
            $this->plan('Unrelated Plan '.$index, ProductFamily::factory()->create(['slug' => 'extra-'.$index]), 2);
        }
        $this->assertGreaterThan(0, $oneFamilySelects);
        $this->assertSame($oneFamilySelects, $readFragment());
        $this->get('/?family=alpha&q=plan')->assertOk()
            ->assertHeaderMissing('X-Storefront-Catalog')
            ->assertSee('<html', false)
            ->assertSee('Alpha Plan');
    }

    private function plan(string $name, ?ProductFamily $family, int $stock): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'description' => 'Catalog fixture description',
            'product_family_id' => $family?->id,
            'stock' => $stock,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function recordSale(Product $product, int $quantity, \DateTimeInterface $date, string $status = 'paid'): void
    {
        $order = Order::factory()->create(['order_status' => 'completed', 'payment_status' => $status]);
        OrderItem::factory()->for($order)->for($product)->create(['quantity' => $quantity]);
        Payment::factory()->for($order)->create(['status' => $status, 'paid_at' => $date]);
    }
}
