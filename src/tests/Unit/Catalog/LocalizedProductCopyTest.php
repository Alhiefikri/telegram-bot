<?php

namespace Tests\Unit\Catalog;

use App\Catalog\LocalizedProductCopy;
use PHPUnit\Framework\TestCase;

final class LocalizedProductCopyTest extends TestCase
{
    public function test_column_maps_locales_onto_base_and_variant_columns(): void
    {
        $this->assertSame('description', LocalizedProductCopy::column('description', 'id'));
        $this->assertSame('description_ms', LocalizedProductCopy::column('description', 'ms'));
        $this->assertSame('warranty_policy_en', LocalizedProductCopy::column('warranty_policy', 'en'));
        $this->assertSame('description_en', LocalizedProductCopy::column('description', 'EN'));
        $this->assertSame('description_en', LocalizedProductCopy::column('description', 'en-US'));
        $this->assertSame('description', LocalizedProductCopy::column('description', 'fr'));
        $this->assertSame('description', LocalizedProductCopy::column('description', null));
    }

    public function test_all_columns_lists_base_and_variants_for_every_field(): void
    {
        $this->assertSame([
            'description',
            'description_ms',
            'description_en',
            'warranty_policy',
            'warranty_policy_ms',
            'warranty_policy_en',
        ], LocalizedProductCopy::allColumns());
    }

    public function test_resolve_prefers_a_filled_variant_and_reports_its_column(): void
    {
        $copy = LocalizedProductCopy::extract([
            'description' => 'Deskripsi Indonesia',
            'description_ms' => 'Penerangan Melayu',
            'description_en' => '   ',
        ]);

        $this->assertSame(
            ['column' => 'description_ms', 'text' => 'Penerangan Melayu'],
            LocalizedProductCopy::resolve($copy, 'description', 'ms'),
        );
        $this->assertSame(
            ['column' => 'description', 'text' => 'Deskripsi Indonesia'],
            LocalizedProductCopy::resolve($copy, 'description', 'en'),
        );
        $this->assertSame(
            ['column' => 'description', 'text' => 'Deskripsi Indonesia'],
            LocalizedProductCopy::resolve($copy, 'description', 'id'),
        );
        $this->assertSame(
            ['column' => 'description', 'text' => 'Deskripsi Indonesia'],
            LocalizedProductCopy::resolve($copy, 'description', 'fr'),
        );
    }

    public function test_text_is_empty_when_both_the_variant_and_the_base_copy_are_empty(): void
    {
        $copy = LocalizedProductCopy::extract(['description_ms' => 'Penerangan Melayu']);

        $this->assertSame('Penerangan Melayu', LocalizedProductCopy::text($copy, 'description', 'ms'));
        $this->assertSame('', LocalizedProductCopy::text($copy, 'description', 'en'));
        $this->assertSame('', LocalizedProductCopy::text($copy, 'warranty_policy', 'ms'));
    }

    public function test_extract_normalizes_every_localizable_column_to_a_string_or_null(): void
    {
        $copy = LocalizedProductCopy::extract([
            'description' => 'Deskripsi Indonesia',
            'warranty_policy_en' => 'English policy',
            'warranty_policy_ms' => '',
            'name' => 'IGNORED',
        ]);

        $this->assertSame([
            'description' => 'Deskripsi Indonesia',
            'description_ms' => null,
            'description_en' => null,
            'warranty_policy' => null,
            'warranty_policy_ms' => '',
            'warranty_policy_en' => 'English policy',
        ], $copy);
    }
}
