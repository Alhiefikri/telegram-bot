<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductFamily> */
class ProductFamilyFactory extends Factory
{
    protected $model = ProductFamily::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'image_path' => null,
            'sort_order' => 0,
        ];
    }
}
