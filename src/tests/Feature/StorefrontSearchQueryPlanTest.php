<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontSearchQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_storefront_search_plan_stays_under_documented_threshold(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL query-plan assertion only runs on the pgsql test connection.');
        }

        $fixtureProducts = (int) env('STOREFRONT_SEARCH_PLAN_PRODUCT_COUNT', 500);
        $budgetMs = (float) env('STOREFRONT_SEARCH_PLAN_MAX_MS', 75);

        Product::factory()
            ->count($fixtureProducts)
            ->sequence(fn ($sequence) => [
                'name' => 'Premium Catalog Probe '.$sequence->index,
                'description' => 'Storefront search plan fixture '.$sequence->index,
                'stock' => 2,
                'reserved_stock' => 0,
                'is_active' => true,
            ])
            ->create();

        $plan = DB::select(
            'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
             SELECT id
             FROM products
             WHERE deleted_at IS NULL
                AND is_active = true
                AND (LOWER(name) LIKE ? OR LOWER(description) LIKE ?)
             ORDER BY name, id
             LIMIT 24',
            ['%premium%', '%premium%'],
        );

        $rawPlan = $plan[0]->{'QUERY PLAN'} ?? $plan[0]->{'query plan'} ?? null;
        $decodedPlan = is_string($rawPlan) ? json_decode($rawPlan, true, flags: JSON_THROW_ON_ERROR) : $rawPlan;
        $executionMs = (float) $decodedPlan[0]['Execution Time'];

        $this->assertLessThan(
            $budgetMs,
            $executionMs,
            "Storefront search plan took {$executionMs}ms for {$fixtureProducts} products. When this fails at the target catalog size, add a measured PostgreSQL pg_trgm GIN index or full-text strategy instead of relying on the bounded LIKE scan.",
        );
    }
}
