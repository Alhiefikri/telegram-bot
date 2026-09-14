<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class RepairProductFamilySlugsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_observed_netflix_slug_repair_is_name_based_audited_idempotent_and_publicly_redirected(): void
    {
        $netflix = ProductFamily::factory()->create([
            'name' => 'Netflix',
            'slug' => 'gemini',
        ]);
        ProductFamily::factory()->create([
            'name' => 'Gemini AI',
            'slug' => 'gemini-ai',
        ]);
        Product::factory()->create([
            'product_family_id' => $netflix->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $dryRun = Artisan::call('storefront:repair-family-slugs');
        $this->assertSame(0, $dryRun);
        $this->assertStringContainsString('Name/slug mismatch: name=Netflix slug=gemini normalized_name=netflix', Artisan::output());
        $this->assertSame('gemini', $netflix->fresh()->slug);
        $this->assertDatabaseCount('product_family_slug_redirects', 0);
        $this->assertDatabaseCount('audit_logs', 0);

        $apply = Artisan::call('storefront:repair-family-slugs', ['--apply' => true]);
        $this->assertSame(0, $apply, Artisan::output());
        $this->assertSame('netflix', $netflix->fresh()->slug);
        $this->assertDatabaseHas('product_family_slug_redirects', [
            'old_slug' => 'gemini',
            'product_family_id' => $netflix->id,
        ]);
        $audit = AuditLog::query()
            ->where('action', 'product_family.slug_changed')
            ->where('subject_id', (string) $netflix->id)
            ->sole();
        $this->assertSame(['slug' => 'gemini'], $audit->old_values);
        $this->assertSame(['slug' => 'netflix', 'old_slug_alias' => 'gemini'], $audit->new_values);
        $this->get('/produk-keluarga/gemini')
            ->assertStatus(301)
            ->assertRedirect(route('storefront.product-families.show', ['productFamily' => 'netflix']));

        $auditCount = AuditLog::query()->where('action', 'product_family.slug_changed')->count();
        $secondApply = Artisan::call('storefront:repair-family-slugs', ['--apply' => true]);
        $this->assertSame(0, $secondApply, Artisan::output());
        $this->assertSame($auditCount, AuditLog::query()->where('action', 'product_family.slug_changed')->count());
        $this->assertSame(1, ProductFamilySlugRedirect::query()->where('old_slug', 'gemini')->count());
    }

    public function test_actual_gemini_owner_gets_a_unique_name_derived_slug_before_netflix_repair(): void
    {
        $netflix = ProductFamily::factory()->create([
            'name' => 'Netflix',
            'slug' => 'legacy-netflix',
        ]);
        $gemini = ProductFamily::factory()->create([
            'name' => 'Gemini AI',
            'slug' => 'gemini',
        ]);

        $apply = Artisan::call('storefront:repair-family-slugs', ['--apply' => true]);
        $this->assertSame(0, $apply, Artisan::output());
        $this->assertSame('netflix', $netflix->fresh()->slug);
        $this->assertSame('gemini-ai', $gemini->fresh()->slug);
        $this->assertDatabaseHas('product_family_slug_redirects', [
            'old_slug' => 'legacy-netflix',
            'product_family_id' => $netflix->id,
        ]);
        $this->assertDatabaseHas('product_family_slug_redirects', [
            'old_slug' => 'gemini',
            'product_family_id' => $gemini->id,
        ]);
        $this->assertSame(2, AuditLog::query()->where('action', 'product_family.slug_changed')->count());

        $secondApply = Artisan::call('storefront:repair-family-slugs', ['--apply' => true]);
        $this->assertSame(0, $secondApply, Artisan::output());
        $this->assertSame(2, AuditLog::query()->where('action', 'product_family.slug_changed')->count());
    }
}
