<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductFamilySlugRedirect> */
class ProductFamilySlugRedirectFactory extends Factory
{
    protected $model = ProductFamilySlugRedirect::class;

    public function definition(): array
    {
        $family = ProductFamily::factory()->create();

        return [
            'old_slug' => $family->slug.'-old',
            'product_family_id' => $family->id,
        ];
    }
}
