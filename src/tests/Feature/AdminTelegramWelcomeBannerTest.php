<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StorefrontSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AdminTelegramWelcomeBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_replace_and_preview_the_telegram_welcome_banner(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.telegram-welcome-banner.update'), [
                'image' => UploadedFile::fake()->image('welcome-one.png', 1200, 800),
            ])
            ->assertRedirect('/admin?tab=telegram-channel#admin-section-telegram-welcome-banner')
            ->assertSessionHas('status', 'Telegram Welcome Banner berhasil diperbarui.');

        $setting = StorefrontSetting::query()->firstOrFail();
        $firstPath = $setting->telegram_welcome_banner_path;

        $this->assertStringStartsWith('telegram/welcome-banners/', $firstPath);
        $this->assertStringEndsWith('.webp', $firstPath);
        $this->assertSame(1, $setting->telegram_welcome_banner_version);
        $this->assertNull($setting->telegram_welcome_banner_file_id);
        $this->assertTrue(Storage::disk('public')->exists($firstPath));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'telegram.welcome_banner.updated',
            'subject_type' => StorefrontSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

        $this->get('/admin?tab=telegram-channel')
            ->assertOk()
            ->assertSee('/storage/'.$firstPath, false)
            ->assertSee('Custom aktif · v1');
        $setting->update(['telegram_welcome_banner_file_id' => 'cached-before-replacement']);

        $this->actingAs($admin)
            ->post(route('admin.telegram-welcome-banner.update'), [
                'image' => UploadedFile::fake()->image('welcome-two.png', 900, 600),
            ])
            ->assertRedirect('/admin?tab=telegram-channel#admin-section-telegram-welcome-banner');

        $secondPath = $setting->refresh()->telegram_welcome_banner_path;

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertSame(2, $setting->telegram_welcome_banner_version);
        $this->assertNull($setting->telegram_welcome_banner_file_id);
        $this->assertFalse(Storage::disk('public')->exists($firstPath));
        $this->assertTrue(Storage::disk('public')->exists($secondPath));
    }

    public function test_support_can_view_but_cannot_change_the_telegram_welcome_banner(): void
    {
        Storage::fake('public');
        $support = User::factory()->create(['role' => UserRole::Support]);

        $this->actingAs($support)
            ->post(route('admin.telegram-welcome-banner.update'), [
                'image' => UploadedFile::fake()->image('not-allowed.png'),
            ])
            ->assertForbidden();

        $this->assertNull(StorefrontSetting::query()->firstOrFail()->telegram_welcome_banner_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_cannot_submit_the_banner_form_without_an_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.telegram-welcome-banner.update'))
            ->assertRedirect('/admin?tab=telegram-channel#admin-section-telegram-welcome-banner')
            ->assertSessionHasErrorsIn('welcomeBanner', 'image');

        $this->assertNull(StorefrontSetting::query()->firstOrFail()->telegram_welcome_banner_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_cannot_submit_a_banner_larger_than_five_megabytes(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.telegram-welcome-banner.update'), [
                'image' => UploadedFile::fake()->image('too-large.png', 120, 80)->size(5121),
            ])
            ->assertRedirect('/admin?tab=telegram-channel#admin-section-telegram-welcome-banner')
            ->assertSessionHasErrorsIn('welcomeBanner', 'image');

        $this->assertNull(StorefrontSetting::query()->firstOrFail()->telegram_welcome_banner_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_cannot_submit_a_banner_exceeding_the_dimension_limit(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.telegram-welcome-banner.update'), [
                'image' => UploadedFile::fake()->image('too-wide.png', 6001, 1),
            ])
            ->assertRedirect('/admin?tab=telegram-channel#admin-section-telegram-welcome-banner')
            ->assertSessionHasErrorsIn('welcomeBanner', 'image');

        $this->assertNull(StorefrontSetting::query()->firstOrFail()->telegram_welcome_banner_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guest_cannot_upload_a_telegram_welcome_banner(): void
    {
        Storage::fake('public');

        $this->post(route('admin.telegram-welcome-banner.update'), [
            'image' => UploadedFile::fake()->image('guest.png'),
        ])->assertRedirect('/admin/login');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_dashboard_does_not_report_a_cached_file_id_for_a_missing_custom_banner(): void
    {
        Storage::fake('public');
        StorefrontSetting::query()->firstOrFail()->update([
            'telegram_welcome_banner_path' => 'telegram/welcome-banners/deleted.webp',
            'telegram_welcome_banner_file_id' => 'stale-file-id',
            'telegram_welcome_banner_version' => 6,
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get('/admin?tab=telegram-channel')
            ->assertOk()
            ->assertSee('Fallback aktif')
            ->assertSee('file_id di-cache saat pengiriman pertama')
            ->assertDontSee('file_id Telegram siap');
    }

    public function test_invalid_welcome_banner_upload_returns_to_the_dedicated_error_bag(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->from('/admin?tab=telegram-channel')
            ->post(route('admin.telegram-welcome-banner.update'), [
                'image' => UploadedFile::fake()->create('banner.txt', 10, 'text/plain'),
            ])
            ->assertRedirect('/admin?tab=telegram-channel#admin-section-telegram-welcome-banner')
            ->assertSessionHasErrorsIn('welcomeBanner', 'image');
    }
}
