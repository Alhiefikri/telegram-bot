<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StorefrontSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorefrontBannersTest extends TestCase
{
    use RefreshDatabase;

    private function slots(): array
    {
        return array_fill(0, 5, ['title' => '', 'link' => null]);
    }

    public function test_admin_can_publish_replace_and_remove_banners_without_changing_catalog(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $slots = $this->slots();
        $slots[0] = ['title' => 'Promo Oktober', 'link' => '/katalog', 'image' => UploadedFile::fake()->image('banner.png', 1600, 400)];
        $this->get('/')->assertOk()->assertDontSee('Promo Oktober');
        $this->actingAs($admin)->post(route('admin.storefront-banners.update'), ['version' => 0, 'banners' => $slots])
            ->assertRedirect('/admin?tab=produk#admin-storefront-banners');
        $setting = StorefrontSetting::current();
        $first = $setting->homepage_banners[0]['path'];
        Storage::disk('public')->assertExists($first);
        $this->assertSame(1, $setting->homepage_banners_version);
        $this->get('/')->assertOk()->assertSee('Promo Oktober')->assertSee('/storage/'.$first, false);
        $this->get('/admin?tab=produk')->assertOk()->assertSee('Simpan banner beranda');
        $this->assertDatabaseHas('audit_logs', ['action' => 'storefront.banners.updated']);
        $slots[0]['image'] = UploadedFile::fake()->image('replacement.png', 2400, 600);
        $this->post(route('admin.storefront-banners.update'), ['version' => 1, 'banners' => $slots])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($first);
        $second = $setting->refresh()->homepage_banners[0]['path'];
        Storage::disk('public')->assertExists($second);
        $slots[0] = ['remove' => true];
        $this->post(route('admin.storefront-banners.update'), ['version' => 2, 'banners' => $slots])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($second);
        $this->get('/')->assertOk()->assertDontSee('Promo Oktober')->assertSee('storefront-banner-empty');
    }

    public function test_stale_editor_cannot_overwrite_current_banners_or_leave_uploaded_files(): void
    {
        Storage::fake('public');
        StorefrontSetting::current()->update(['homepage_banners_version' => 1]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $slots = $this->slots();
        $slots[0]['image'] = UploadedFile::fake()->image('stale.png', 1600, 400);
        $this->actingAs($admin)->post(route('admin.storefront-banners.update'), ['version' => 0, 'banners' => $slots])
            ->assertSessionHasErrors(['version'], null, 'storefrontBanners');
        $this->assertSame([], Storage::disk('public')->allFiles('storefront/banners'));
        $this->assertSame(1, StorefrontSetting::current()->homepage_banners_version);
    }

    public function test_support_cannot_change_banners(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Support]))
            ->post(route('admin.storefront-banners.update'), ['version' => 0, 'banners' => $this->slots()])->assertForbidden();
    }

    public function test_banner_rejects_executable_links_and_non_image_uploads(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['javascript:alert(1)', '//example.com', '/\\example.com'] as $link) {
            $slots = $this->slots();
            $slots[0]['link'] = $link;
            $this->actingAs($admin)->post(route('admin.storefront-banners.update'), ['version' => 0, 'banners' => $slots])
                ->assertSessionHasErrors(['banners.0.link'], null, 'storefrontBanners');
        }
        $slots = $this->slots();
        $slots[0]['image'] = UploadedFile::fake()->create('banner.svg', 5, 'image/svg+xml');
        $this->post(route('admin.storefront-banners.update'), ['version' => 0, 'banners' => $slots])
            ->assertSessionHasErrors(['banners.0.image'], null, 'storefrontBanners');
    }
}
