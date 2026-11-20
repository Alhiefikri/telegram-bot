<?php

namespace Tests\Feature;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Storefront\StorefrontPresenter;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Support\StorefrontSignedInvoiceScenarios;
use Tests\TestCase;

class StorefrontLocaleTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontSignedInvoiceScenarios;

    private const COOKIE = 'storefront_locale';

    public function test_home_purchase_guide_and_family_use_visitor_copy_without_rewriting_merchant_content(): void
    {
        $family = ProductFamily::factory()->create(['name' => 'Merchant Family', 'description' => 'Merchant family details']);
        Product::factory()->for($family, 'family')->create(['name' => 'Merchant Monthly', 'stock' => 2]);
        Product::factory()->for($family, 'family')->create(['name' => 'Merchant Annual', 'stock' => 0]);

        foreach ([
            'id' => ['Belanja produk digital dalam tiga langkah.', 'Checkout sebagai tamu', '2 Plan', '1 tersedia', 'Lihat detail Plan'],
            'ms' => ['Beli produk digital dalam tiga langkah.', 'Buat pesanan sebagai tetamu', '2 Plan', '1 tersedia', 'Lihat butiran Plan'],
            'en' => ['Buy digital products in three steps.', 'Check out as a guest', '2 Plans', '1 available', 'View plan details'],
        ] as $locale => [$guide, $step, $count, $available, $detail]) {
            $this->withCookie(self::COOKIE, $locale);
            $this->get('/')->assertOk()->assertSee($guide)->assertSee($step);
            $this->get('/shop')->assertOk()->assertSee($count)->assertSee($available)->assertSee('Merchant Family');
            $this->get(route('storefront.product-families.show', $family->slug))->assertOk()
                ->assertSee($count)->assertSee($available)->assertSee($detail)
                ->assertSee('Merchant Family')->assertSee('Merchant family details')->assertSee('Merchant Annual');
        }
    }

    public function test_funnel_copy_and_provider_economics_follow_the_visitor_locale(): void
    {
        $this->pakasirConfigIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        config(['services.support.whatsapp_number' => '6281234567890']);
        [$order, $payment] = $this->pendingNativeQrisOrder(66_000, 500, 66_500, 'fixture');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $product = Product::factory()->create([
            'name' => 'Merchant Plan',
            'description' => 'Base merchant details',
            'description_en' => 'English merchant details',
            'description_ms' => ' ',
            'stock' => 3,
            'is_active' => true,
        ]);
        $invoice = URL::temporarySignedRoute('storefront.shop.orders.show', now()->addHour(), $order, absolute: false);
        $deadline = $payment->expired_at->toIso8601String();
        $total = app(StorefrontPresenter::class)->idr($payment->provider_total_payment);

        foreach ([
            'id' => ['Base merchant details', 'Nama pembeli', 'Pindai QRIS', 'Masukkan nomor invoice.', 'Kategori masalah:'],
            'ms' => ['Base merchant details', 'Nama pembeli', 'Imbas QRIS', 'Masukkan nombor invois.', 'Kategori masalah:'],
            'en' => ['English merchant details', 'Buyer name', 'Scan the QRIS code', 'Enter the invoice number.', 'Issue:'],
        ] as $locale => [$details, $buyerName, $paymentInstruction, $required, $issueLabel]) {
            $this->withCookie(self::COOKIE, $locale);
            $this->get(route('storefront.products.show', $product))->assertOk()
                ->assertSee('Merchant Plan')->assertSee($details);
            $this->get(route('storefront.shop.checkout.product', $product))->assertOk()
                ->assertSee('Merchant Plan')->assertSee($buyerName);
            $this->from('/akses-pesanan')->post('/akses-pesanan', ['access_key' => ''])
                ->assertRedirect('/akses-pesanan')->assertSessionHasErrors(['invoice_number' => $required]);
            $this->get($invoice)->assertOk()->assertSee('<html lang="'.$locale.'"', false)
                ->assertSee($total)->assertSee($deadline)->assertSee($paymentInstruction)->assertSee($issueLabel);
        }
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_funnel_translations_have_the_same_keys_and_placeholders(): void
    {
        $base = require lang_path('id/storefront_funnel.php');
        foreach (['ms', 'en'] as $locale) {
            $translations = require lang_path($locale.'/storefront_funnel.php');
            $this->assertSame(array_keys($base), array_keys($translations));
            foreach ($base as $key => $text) {
                $this->assertNotSame('', trim($translations[$key]), $locale.'.'.$key);
                preg_match_all('/:[a-z_]+\b/', $text, $expected);
                preg_match_all('/:[a-z_]+\b/', $translations[$key], $actual);
                $this->assertEqualsCanonicalizing($expected[0], $actual[0], $locale.'.'.$key);
            }
        }
    }

    public function test_cold_and_warm_catalog_html_are_isolated_by_locale_and_filters_bypass_html_cache(): void
    {
        config(['storefront.locale' => 'id']);
        $hits = [];
        Event::listen(CacheHit::class, function (CacheHit $event) use (&$hits): void {
            if (str_starts_with($event->key, 'storefront.response.v1.')) {
                $hits[] = $event->key;
            }
        });
        $jars = [];
        foreach (['en', 'ms'] as $locale) {
            $response = $this->get('/bahasa/'.$locale)->assertStatus(303)->assertHeader('Location', '/')
                ->assertHeader('Cache-Control', 'no-store, private')->assertCookie(self::COOKIE, $locale);
            $jars[$locale] = [self::COOKIE => $response->getCookie(self::COOKIE, decrypt: false)->getValue()];
            $this->assertCount(1, $response->headers->getCookies());
        }
        $jars['id'] = [];

        foreach ([0, 1, 2] as $pass) {
            foreach ($jars as $locale => $jar) {
                $response = $this->call('GET', $pass === 2 ? '/?q=filter' : '/', [], $jar)
                    ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                    ->assertHeader('Vary', 'X-Storefront-Catalog')
                    ->assertSee('<html lang="'.$locale.'"', false);
                $this->assertStringContainsString($locale === 'en' ? 'Language' : 'Bahasa', $response->getContent());
                foreach (['Set-Cookie', 'CDN-Cache-Control', 'Surrogate-Control'] as $header) {
                    $this->assertFalse($response->headers->has($header));
                }
            }
            $this->assertCount($pass === 0 ? 0 : 3, $hits);
        }
        $this->assertCount(3, array_unique($hits));
    }

    public function test_default_and_invalid_cookies_do_not_change_other_channels(): void
    {
        config(['storefront.locale' => 'ms']);
        app()->setLocale('id');
        Route::middleware('web')->get('/admin/locale-test', fn () => __('storefront_locale.label').':'.app('translator')->getLocale());
        Route::middleware('api')->get('/api/locale-test', fn () => app('translator')->getLocale());

        $this->get('/')->assertOk()->assertSee('<html lang="ms"', false);
        $this->call('GET', '/', [], [self::COOKIE => 'en'])->assertOk()->assertSee('<html lang="ms"', false);
        $this->withCookie(self::COOKIE, 'en-GB')->get('/')->assertOk()->assertSee('<html lang="ms"', false);
        $this->withCookie(self::COOKIE, 'en')->get('/')->assertOk()->assertSee('<html lang="en"', false);
        $this->get('/admin/locale-test')->assertOk()->assertSee('Bahasa:id');
        $this->get('/api/locale-test')->assertOk()->assertSee('id');
        $this->assertSame('ms', config('storefront.locale'));

        config(['storefront.locale' => 'fr']);
        $this->call('GET', '/')->assertOk()->assertSee('<html lang="id"', false);
    }

    public function test_public_switch_retains_catalog_context_and_rejects_non_catalog_returns(): void
    {
        $response = $this->get(secure_url('/bahasa/en').'?'.http_build_query([
            'return' => '/?family=creative&q=annual%20plan&page=2&ignored=1',
        ]))->assertStatus(303)->assertHeader('Location', '/?family=creative&q=annual%20plan&page=2');
        $cookie = $response->getCookie(self::COOKIE, decrypt: false);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEmpty($cookie->getDomain());

        foreach (['https://external.test/', '//external.test/', '/\\external.test', '/%2fexternal.test', '/shop/orders/1?signature=unused', '/admin'] as $return) {
            $this->get('/bahasa/en?'.http_build_query(['return' => $return]))->assertBadRequest()->assertCookieMissing(self::COOKIE);
            $this->post('/bahasa', ['locale' => 'en', 'return' => $return])->assertStatus(str_starts_with($return, '/shop/orders') ? 403 : 400)
                ->assertCookieMissing(self::COOKIE);
        }
        $this->get('/bahasa/fr')->assertBadRequest()->assertCookieMissing(self::COOKIE);
        $this->post('/bahasa', ['locale' => ['en'], 'return' => '/akses-pesanan'])->assertBadRequest()->assertCookieMissing(self::COOKIE);
    }

    public function test_signed_invoice_and_status_still_require_original_signature_after_locale_switch(): void
    {
        $order = Order::factory()->create();
        Payment::factory()->for($order)->create(['provider' => 'sakurupiah', 'method' => 'qris']);
        $expires = now()->addMinute();
        $relative = URL::temporarySignedRoute('storefront.shop.orders.show', $expires, ['order' => $order, 'context' => 'a + b'], absolute: false);
        $absolute = URL::temporarySignedRoute('storefront.shop.orders.show', $expires, ['order' => $order]);
        $status = URL::temporarySignedRoute('storefront.shop.orders.status', $expires, $order, absolute: false);

        foreach ([$relative, substr($absolute, strlen(request()->getSchemeAndHttpHost()))] as $invoice) {
            $this->get($invoice)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertSee('action="/bahasa" method="POST"', false)
                ->assertSee('name="return" value="'.e($invoice).'"', false);
            $response = $this->post('/bahasa', ['locale' => 'ms', 'return' => $invoice])
                ->assertStatus(303)->assertHeader('Location', $invoice)
                ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');

            $jar = [self::COOKIE => $response->getCookie(self::COOKIE, decrypt: false)->getValue()];
            $this->call('GET', $invoice, [], $jar)->assertOk()->assertSee('<html lang="ms"', false)
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->call('POST', $status, [], $jar, [], ['HTTP_X_STOREFRONT_AUTO_POLL' => '1'])
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->call('GET', '/shop/orders/'.$order->id, [], $jar)->assertForbidden();
            $this->call('GET', '/akses-pesanan/'.$order->id, [], $jar)->assertForbidden();
            $this->post('/bahasa', ['locale' => 'en', 'return' => $invoice.'&extra=1'])->assertForbidden()->assertCookieMissing(self::COOKIE);
        }

        $this->travel(2)->minutes();
        $this->post('/bahasa', ['locale' => 'en', 'return' => $relative])->assertForbidden()->assertCookieMissing(self::COOKIE);
        $this->get($relative)->assertForbidden();
        $this->post($status)->assertForbidden();
    }
}
