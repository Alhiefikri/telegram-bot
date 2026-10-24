<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\PaymentQrTemplateSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        $this->assertFalse($setting->enabled);
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
            ->assertSee('/storage/'.$setting->image_path, false);
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

    public function test_geometry_update_cannot_restore_an_image_replaced_before_the_lock(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $original = $this->uploadTemplate('original.png');
        $replacement = null;

        $this->beforeTemplateLock(function () use (&$replacement): void {
            $replacement = $this->uploadTemplate('replacement.png');
        });

        $this->post(route('admin.payment-qr-template.update'), [
            'qr_x' => 250,
            'qr_y' => 250,
            'qr_width' => 400,
            'qr_height' => 400,
        ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasErrorsIn('qrTemplate', 'image');

        $this->assertNotNull($replacement);
        $setting = PaymentQrTemplateSetting::current();
        $this->assertSame($replacement->image_path, $setting?->image_path);
        $this->assertSame(520, $setting?->qr_width);
        $this->assertSame(2, $setting?->version);
        $this->assertFalse(Storage::disk('public')->exists($original->image_path));
        $this->assertSame([$replacement->image_path], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_geometry_update_cannot_restore_an_image_removed_before_the_lock(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->uploadTemplate('original.png');

        $this->beforeTemplateLock(function (): void {
            $this->post(route('admin.payment-qr-template.destroy'))
                ->assertRedirect(self::REDIRECT)
                ->assertSessionHasNoErrors();
        });

        $this->post(route('admin.payment-qr-template.update'), [
            'qr_x' => 250,
            'qr_y' => 250,
            'qr_width' => 400,
            'qr_height' => 400,
        ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasErrorsIn('qrTemplate', 'image');

        $setting = PaymentQrTemplateSetting::current();
        $this->assertNull($setting?->image_path);
        $this->assertNull($setting?->qr_width);
        $this->assertSame(2, $setting?->version);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_stale_upload_is_deleted_without_deleting_the_concurrent_replacement(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $original = $this->uploadTemplate('original.png');
        $replacement = null;

        $this->beforeTemplateLock(function () use (&$replacement): void {
            $replacement = $this->uploadTemplate('replacement.png');
        });

        $this->post(route('admin.payment-qr-template.update'), [
            'image' => $this->templateUpload('stale.png'),
            'qr_x' => 250,
            'qr_y' => 250,
            'qr_width' => 400,
            'qr_height' => 400,
        ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasErrorsIn('qrTemplate', 'image');

        $this->assertNotNull($replacement);
        $setting = PaymentQrTemplateSetting::current();
        $this->assertSame($replacement->image_path, $setting?->image_path);
        $this->assertSame(520, $setting?->qr_width);
        $this->assertSame(2, $setting?->version);
        $this->assertFalse(Storage::disk('public')->exists($original->image_path));
        $this->assertSame([$replacement->image_path], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_geometry_update_rejects_a_changed_version_even_when_the_image_is_unchanged(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $original = $this->uploadTemplate('original.png');

        $this->beforeTemplateLock(function (): void {
            $this->post(route('admin.payment-qr-template.update'), [
                'qr_x' => 250,
                'qr_y' => 250,
                'qr_width' => 400,
                'qr_height' => 400,
            ])
                ->assertRedirect(self::REDIRECT)
                ->assertSessionHasNoErrors();
        });

        $this->post(route('admin.payment-qr-template.update'), [
            'qr_x' => 250,
            'qr_y' => 250,
            'qr_width' => 300,
            'qr_height' => 300,
        ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasErrorsIn('qrTemplate', 'image');

        $setting = PaymentQrTemplateSetting::current();
        $this->assertSame($original->image_path, $setting?->image_path);
        $this->assertSame(400, $setting?->qr_width);
        $this->assertSame(2, $setting?->version);
        $this->assertSame([$original->image_path], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('audit_logs', 2);
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
            ->assertRedirect(self::REDIRECT);

        $setting = PaymentQrTemplateSetting::current();
        $this->assertFalse($setting?->isConfigured() ?? false);
        $this->assertSame(2, $setting?->version);
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'payment.qr_template.removed',
        ]);

        $this->get('/admin?tab=settings&section=pembayaran')
            ->assertOk();
    }

    public function test_removal_deletes_and_audits_the_image_actually_replaced_under_lock(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->uploadTemplate('original.png');
        $replacement = null;

        $this->beforeTemplateLock(function () use (&$replacement): void {
            $replacement = $this->uploadTemplate('replacement.png');
        });

        $this->post(route('admin.payment-qr-template.destroy'))
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasNoErrors();

        $this->assertNotNull($replacement);
        $setting = PaymentQrTemplateSetting::current();
        $this->assertNull($setting?->image_path);
        $this->assertSame(3, $setting?->version);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $audit = AuditLog::query()->where('action', 'payment.qr_template.removed')->sole();
        $this->assertSame(
            $replacement->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height', 'version']),
            $audit->old_values,
        );
        $this->assertSame(
            $setting->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height', 'version']),
            $audit->new_values,
        );
    }

    public function test_removal_keeps_its_locked_audit_snapshot_and_preserves_a_later_upload(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $original = $this->uploadTemplate('original.png');
        $replacement = null;
        $dispatcher = PaymentQrTemplateSetting::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        PaymentQrTemplateSetting::setEventDispatcher(clone $dispatcher);
        PaymentQrTemplateSetting::saved(function (PaymentQrTemplateSetting $setting) use (&$replacement): void {
            if ($setting->image_path === null && $setting->version === 2) {
                $replacement = $this->uploadTemplate('replacement.png');
            }
        });

        try {
            $this->post(route('admin.payment-qr-template.destroy'))
                ->assertRedirect(self::REDIRECT)
                ->assertSessionHasNoErrors();
        } finally {
            PaymentQrTemplateSetting::setEventDispatcher($dispatcher);
        }

        $this->assertNotNull($replacement);
        $setting = PaymentQrTemplateSetting::current();
        $this->assertSame($replacement->image_path, $setting?->image_path);
        $this->assertSame(3, $setting?->version);
        $this->assertFalse(Storage::disk('public')->exists($original->image_path));
        $this->assertSame([$replacement->image_path], Storage::disk('public')->allFiles());

        $audit = AuditLog::query()->where('action', 'payment.qr_template.removed')->sole();
        $this->assertSame(
            $original->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height', 'version']),
            $audit->old_values,
        );
        $this->assertSame([
            'image_path' => null,
            'qr_x' => null,
            'qr_y' => null,
            'qr_width' => null,
            'qr_height' => null,
            'version' => 2,
        ], $audit->new_values);
    }

    public function test_admin_can_switch_off_and_on_without_losing_the_template(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $original = $this->uploadTemplate('template.png');

        foreach ([true, false, true] as $enabled) {
            $this->post(route('admin.payment-qr-template.status'), ['enabled' => (int) $enabled])
                ->assertRedirect(self::REDIRECT)
                ->assertSessionHasNoErrors();
            $setting = PaymentQrTemplateSetting::current();
            $this->assertSame($enabled, $setting->enabled);
            $this->assertSame(
                $original->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height']),
                $setting->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height']),
            );
            $this->assertSame($this->templateContents(), Storage::disk('public')->get($setting->image_path));
        }

        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'payment.qr_template.disabled']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'payment.qr_template.enabled']);
        $version = PaymentQrTemplateSetting::current()->version;
        $audits = AuditLog::count();
        $this->post(route('admin.payment-qr-template.status'), ['enabled' => 1])->assertSessionHasNoErrors();
        $this->assertSame($version, PaymentQrTemplateSetting::current()->version);
        $this->assertSame($audits, AuditLog::count());

        $this->uploadTemplate('replacement.png');
        $this->assertTrue(PaymentQrTemplateSetting::current()->enabled);
        $this->post(route('admin.payment-qr-template.destroy'))->assertSessionHasNoErrors();
        $this->assertFalse(PaymentQrTemplateSetting::current()->enabled);
    }

    public function test_enable_requires_a_saved_template_file_but_disable_does_not(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->post(route('admin.payment-qr-template.status'), ['enabled' => 1])
            ->assertSessionHasErrorsIn('qrTemplate', 'enabled');
        $this->assertFalse(PaymentQrTemplateSetting::current()->enabled);
        $setting = $this->uploadTemplate('template.png');
        Storage::disk('public')->delete($setting->image_path);
        $this->post(route('admin.payment-qr-template.status'), ['enabled' => 1])
            ->assertSessionHasErrorsIn('qrTemplate', 'enabled');
        $this->assertFalse(PaymentQrTemplateSetting::current()->enabled);
        $setting->forceFill(['enabled' => true])->save();
        $this->post(route('admin.payment-qr-template.status'), ['enabled' => 0])
            ->assertSessionHasNoErrors();
        $this->assertFalse(PaymentQrTemplateSetting::current()->enabled);
    }

    public function test_status_rejects_unauthorized_or_malformed_requests(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Support]))
            ->post(route('admin.payment-qr-template.status'), ['enabled' => 1])
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.payment-qr-template.status'), ['enabled' => 'invalid'])
            ->assertSessionHasErrorsIn('qrTemplate', 'enabled');
        $this->assertFalse(PaymentQrTemplateSetting::current()->enabled);
        $this->assertSame(0, PaymentQrTemplateSetting::current()->version);
    }

    public function test_in_flight_geometry_update_cannot_override_a_status_change(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $original = $this->uploadTemplate('template.png');
        $this->post(route('admin.payment-qr-template.status'), ['enabled' => 1])->assertSessionHasNoErrors();
        $this->beforeTemplateLock(function (): void {
            $this->post(route('admin.payment-qr-template.status'), ['enabled' => 0])->assertSessionHasNoErrors();
        });
        $this->post(route('admin.payment-qr-template.update'), [
            'qr_x' => 250, 'qr_y' => 250, 'qr_width' => 400, 'qr_height' => 400,
        ])->assertSessionHasErrorsIn('qrTemplate', 'image');
        $setting = PaymentQrTemplateSetting::current();
        $this->assertFalse($setting->enabled);
        $this->assertSame(520, $setting->qr_width);
        $this->assertSame($original->image_path, $setting->image_path);
        $this->assertTrue(Storage::disk('public')->exists($setting->image_path));
    }

    private function beforeTemplateLock(callable $interleave): void
    {
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $armed = true;

        $connection->beforeExecuting(function (string $sql) use ($connection, $transactionLevel, &$armed, $interleave): void {
            if (! $armed
                || $connection->transactionLevel() <= $transactionLevel
                || ! str_starts_with(strtolower(ltrim($sql)), 'select')
                || ! str_contains($sql, 'payment_qr_template_settings')) {
                return;
            }

            // Run the winning request after validation but before the losing request acquires its row lock.
            $armed = false;
            $interleave();
        });
    }

    private function uploadTemplate(string $name): PaymentQrTemplateSetting
    {
        $this->post(route('admin.payment-qr-template.update'), [
            'image' => $this->templateUpload($name),
            'qr_x' => 250,
            'qr_y' => 250,
            'qr_width' => 520,
            'qr_height' => 520,
        ])
            ->assertRedirect(self::REDIRECT)
            ->assertSessionHasNoErrors();

        $setting = PaymentQrTemplateSetting::current();
        $this->assertNotNull($setting);

        return $setting;
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
