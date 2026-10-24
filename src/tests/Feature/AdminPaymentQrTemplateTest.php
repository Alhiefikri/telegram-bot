<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PaymentQrTemplateSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AdminPaymentQrTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = '/admin?tab=settings&section=pembayaran#admin-payment-qr-template';

    public function test_admin_can_upload_a_template_and_geometry(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.payment-qr-template.update'), [
                'image' => $this->templateUpload('template.png'),
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 520,
                'qr_height' => 520,
            ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHas('status', 'Template QR pembayaran berhasil disimpan.');

        $setting = PaymentQrTemplateSetting::current();
        $this->assertNotNull($setting);
        $this->assertTrue($setting->isConfigured());
        $this->assertSame([250, 250, 520, 520], [
            $setting->qr_x,
            $setting->qr_y,
            $setting->qr_width,
            $setting->qr_height,
        ]);
        $this->assertSame(1, $setting->version);
        $this->assertStringStartsWith('payment-qr-templates/', (string) $setting->image_path);
        $this->assertStringEndsWith('.png', (string) $setting->image_path);
        $this->assertSame(
            $this->templateContents(),
            Storage::disk('public')->get((string) $setting->image_path),
            'The template must be stored verbatim so configured coordinates stay accurate.',
        );
        $this->assertTrue(Storage::disk('public')->exists((string) $setting->image_path));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'payment.qr_template.updated',
            'subject_type' => PaymentQrTemplateSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

        $this->get('/admin?tab=settings&section=pembayaran')
            ->assertOk()
            ->assertSee('/storage/'.$setting->image_path, false)
            ->assertSee('Aktif · v1');
    }

    public function test_geometry_update_keeps_the_existing_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post(route('admin.payment-qr-template.update'), [
            'image' => $this->templateUpload('template.png'),
            'qr_x' => 250,
            'qr_y' => 250,
            'qr_width' => 520,
            'qr_height' => 520,
        ]);

        $firstPath = (string) PaymentQrTemplateSetting::current()?->image_path;

        $this->actingAs($admin)
            ->post(route('admin.payment-qr-template.update'), [
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 400,
                'qr_height' => 400,
            ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasNoErrors();

        $setting = PaymentQrTemplateSetting::current();
        $this->assertSame($firstPath, $setting?->image_path);
        $this->assertSame(400, $setting?->qr_width);
        $this->assertSame(2, $setting?->version);
        $this->assertTrue(Storage::disk('public')->exists($firstPath));
    }

    public function test_area_outside_the_image_is_rejected_without_storing_the_upload(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.payment-qr-template.update'), [
                'image' => $this->templateUpload('template.png'),
                'qr_x' => 900,
                'qr_y' => 900,
                'qr_width' => 500,
                'qr_height' => 500,
            ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasErrorsIn('qrTemplate', 'image');

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertFalse(PaymentQrTemplateSetting::current()?->isConfigured() ?? false);
    }

    public function test_area_over_artwork_is_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.payment-qr-template.update'), [
                'image' => $this->templateUpload('template.png'),
                'qr_x' => 0,
                'qr_y' => 0,
                'qr_width' => 520,
                'qr_height' => 520,
            ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasErrorsIn('qrTemplate', 'image');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_first_save_requires_an_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.payment-qr-template.update'), [
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 520,
                'qr_height' => 520,
            ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasErrorsIn('qrTemplate', 'image');

        $this->assertFalse(PaymentQrTemplateSetting::current()?->isConfigured() ?? false);
    }

    public function test_support_cannot_change_the_template(): void
    {
        Storage::fake('public');
        $support = User::factory()->create(['role' => UserRole::Support]);

        $this->actingAs($support)
            ->post(route('admin.payment-qr-template.update'), [
                'image' => $this->templateUpload('template.png'),
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 520,
                'qr_height' => 520,
            ])
            ->assertForbidden();

        $this->actingAs($support)
            ->post(route('admin.payment-qr-template.destroy'))
            ->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_can_deactivate_the_template(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post(route('admin.payment-qr-template.update'), [
            'image' => $this->templateUpload('template.png'),
            'qr_x' => 250,
            'qr_y' => 250,
            'qr_width' => 520,
            'qr_height' => 520,
        ]);
        $path = (string) PaymentQrTemplateSetting::current()?->image_path;

        $this->actingAs($admin)
            ->post(route('admin.payment-qr-template.destroy'))
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHas('status', 'Template QR pembayaran dinonaktifkan. QR tampil seperti sebelumnya.');

        $setting = PaymentQrTemplateSetting::current();
        $this->assertFalse($setting?->isConfigured() ?? false);
        $this->assertSame(2, $setting?->version);
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'payment.qr_template.removed',
        ]);

        $this->get('/admin?tab=settings&section=pembayaran')
            ->assertOk()
            ->assertSee('Nonaktif');
    }

    private function templateUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->templateContents());
    }

    private function templateContents(): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/qr/template-square-box.png'));
        $this->assertIsString($contents);

        return $contents;
    }
}
