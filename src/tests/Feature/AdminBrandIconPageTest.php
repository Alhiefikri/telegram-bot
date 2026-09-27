<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CatalogBrandIcon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBrandIconPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_page_lists_every_brand_with_its_current_emoji_state(): void
    {
        CatalogBrandIcon::query()->where('slug', 'netflix')->sole()->update(['custom_emoji_id' => '5368324170671202286']);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.brand-icons.index'))
            ->assertOk()
            ->assertSee('🎨 Ikon Brand')
            ->assertSee('ChatGPT')
            ->assertSee('Netflix');

        $this->assertSame(1, substr_count($response->getContent(), 'Emoji Telegram tersimpan'));
    }

    public function test_admin_can_update_brand_patterns_and_fallback_emoji(): void
    {
        $brand = CatalogBrandIcon::query()->where('slug', 'netflix')->sole();

        $this->actingAs($this->admin())
            ->patch(route('admin.brand-icons.update', $brand), [
                'label' => 'Netflix ID',
                'patterns' => "netflix\nnflx, Netflix ",
                'fallback_emoji' => '🎬',
                'sort_order' => 4,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $fresh = $brand->fresh();
        $this->assertSame('Netflix ID', $fresh->label);
        $this->assertSame(['netflix', 'nflx'], $fresh->patterns);
        $this->assertSame('🎬', $fresh->fallback_emoji);
        $this->assertSame(4, $fresh->sort_order);
    }

    public function test_brand_patterns_are_required(): void
    {
        $brand = CatalogBrandIcon::query()->where('slug', 'netflix')->sole();

        $this->actingAs($this->admin())
            ->patch(route('admin.brand-icons.update', $brand), [
                'label' => 'Netflix',
                'patterns' => "  \n ",
                'fallback_emoji' => '📺',
                'sort_order' => 1,
            ])
            ->assertSessionHasErrors('patterns');

        $this->assertSame('netflix', $brand->fresh()->patterns[0]);
    }

    public function test_admin_can_clear_a_captured_custom_emoji(): void
    {
        $brand = CatalogBrandIcon::query()->where('slug', 'netflix')->sole();
        $brand->update(['custom_emoji_id' => '5368324170671202286']);

        $this->actingAs($this->admin())
            ->delete(route('admin.brand-icons.custom-emoji.destroy', $brand))
            ->assertRedirect();

        $this->assertNull($brand->fresh()->custom_emoji_id);
    }

    public function test_admin_can_add_a_new_brand(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.brand-icons.store'), [
                'label' => 'Disney+',
                'patterns' => "disney+\ndisney plus",
                'fallback_emoji' => '🏰',
                'sort_order' => 20,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $brand = CatalogBrandIcon::query()->where('slug', 'disney')->sole();
        $this->assertSame('Disney+', $brand->label);
        $this->assertSame(['disney+', 'disney plus'], $brand->patterns);
        $this->assertSame('🏰', $brand->fallback_emoji);
        $this->assertNull($brand->custom_emoji_id);
    }

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get(route('admin.brand-icons.index'))->assertRedirect(route('admin.login'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }
}
