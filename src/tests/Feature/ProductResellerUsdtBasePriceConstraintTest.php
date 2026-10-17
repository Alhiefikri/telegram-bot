<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\NonTransactionalDatabaseTestCase;

/**
 * S02-fix-A (#638): a base dollar price that is `0` is not a usable dollar rail,
 * so the PostgreSQL constraint must refuse to authorize a reseller dollar
 * override above it, while the migration that tightens the constraint repairs
 * legacy rows instead of failing against them.
 */
#[Group('postgres-release')]
final class ProductResellerUsdtBasePriceConstraintTest extends NonTransactionalDatabaseTestCase
{
    private const CONSTRAINT = 'products_reseller_usdt_price_amount_requires_base_price';

    public function test_the_shipped_constraint_requires_a_positive_base_dollar_price(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The reseller base-price CHECK constraint is a PostgreSQL-only guard.');
        }

        $definition = $this->constraintDefinition();
        $this->assertNotNull($definition, 'The reseller base-price constraint must exist after migrations.');
        $this->assertStringContainsString('usdt_price_amount > 0', $definition);

        $productId = Product::factory()->create(['price_amount' => 25_000, 'usdt_price_amount' => 2_000_000])->id;

        foreach ([[null, 'absent'], [0, 'zero']] as [$base, $label]) {
            DB::table('products')->where('id', $productId)->update(['usdt_price_amount' => $base, 'reseller_usdt_price_amount' => null]);

            try {
                DB::table('products')->where('id', $productId)->update(['reseller_usdt_price_amount' => 400_000]);
                $this->fail("The constraint accepted a reseller USDT override above an {$label} base USDT price.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString(self::CONSTRAINT, $exception->getMessage());
            }
        }

        DB::table('products')->where('id', $productId)->update(['usdt_price_amount' => 1, 'reseller_usdt_price_amount' => 400_000]);

        $this->assertSame(400_000, (int) DB::table('products')->where('id', $productId)->value('reseller_usdt_price_amount'));
    }

    public function test_the_migration_removes_an_unusable_override_instead_of_failing_on_legacy_rows(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The reseller base-price CHECK constraint is a PostgreSQL-only guard.');
        }

        $productId = Product::factory()->create(['price_amount' => 25_000, 'usdt_price_amount' => 2_000_000])->id;

        // Recreate the historical lane: a pre-existing row that violates the old
        // constraint because it was added unvalidated and only checked on writes.
        DB::transaction(function () use ($productId): void {
            DB::statement('ALTER TABLE products DROP CONSTRAINT '.self::CONSTRAINT);
            DB::table('products')->where('id', $productId)->update(['usdt_price_amount' => null, 'reseller_usdt_price_amount' => 400_000]);
            DB::statement('ALTER TABLE products ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (reseller_usdt_price_amount IS NULL OR usdt_price_amount IS NOT NULL) NOT VALID');
        });

        $auditsBefore = (int) DB::table('audit_logs')->where('action', 'product.reseller_usdt_override_removed')->count();

        $migration = require database_path('migrations/2026_10_17_000002_require_positive_base_usdt_for_reseller_override.php');
        $migration->up();

        $row = DB::table('products')->where('id', $productId)->first();
        $this->assertNull($row->usdt_price_amount, 'The repair must not invent a base price.');
        $this->assertNull($row->reseller_usdt_price_amount, 'The unusable override must be cleared.');

        $definition = $this->constraintDefinition();
        $this->assertNotNull($definition);
        $this->assertStringContainsString('usdt_price_amount > 0', $definition);

        $audit = DB::table('audit_logs')->where('action', 'product.reseller_usdt_override_removed')->latest('id')->first();
        $this->assertSame($auditsBefore + 1, (int) DB::table('audit_logs')->where('action', 'product.reseller_usdt_override_removed')->count());
        $this->assertNotNull($audit);
        $this->assertSame(['reason', 'products'], array_keys((array) json_decode((string) $audit->new_values, true)));

        // The repaired row is no longer blocked: a positive base price authorizes
        // the override again under the enforced constraint.
        DB::table('products')->where('id', $productId)->update(['usdt_price_amount' => 1, 'reseller_usdt_price_amount' => 400_000]);
        $this->assertSame(400_000, (int) DB::table('products')->where('id', $productId)->value('reseller_usdt_price_amount'));
    }

    private function constraintDefinition(): ?string
    {
        return DB::selectOne('select pg_get_constraintdef(oid) as def from pg_constraint where conname = ?', [self::CONSTRAINT])?->def;
    }
}
