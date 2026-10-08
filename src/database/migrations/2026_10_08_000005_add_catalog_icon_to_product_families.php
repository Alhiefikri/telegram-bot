<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_families', function (Blueprint $table): void {
            $table->string('icon', 32)->nullable()->after('name');
            $table->string('custom_emoji_id')->nullable()->after('icon');
        });

        $this->backfillFamilyIcons();
        $this->releaseMaterialisedMemberIcons();
    }

    public function down(): void
    {
        $this->materialiseMemberIcons();

        Schema::table('product_families', function (Blueprint $table): void {
            $table->dropColumn(['icon', 'custom_emoji_id']);
        });
    }

    /**
     * A family icon used to be derived from its members on every read. Move the
     * value the catalog presented into the family row so the existing look is
     * preserved: the first public-visible member in family order that carries a
     * captured custom emoji supplies the identifier and its glyph, otherwise the
     * first public-visible glyph is used.
     */
    private function backfillFamilyIcons(): void
    {
        $publicVisibleMembers = 'products.product_family_id = product_families.id
            and products.deleted_at is null
            and categories.deleted_at is null
            and products.is_active = true
            and (products.category_id is null or categories.is_active = true)';
        $capturedEmoji = "coalesce(trim(products.custom_emoji_id), '') <> ''";
        $plainGlyph = "coalesce(trim(products.icon), '') <> ''";
        $order = 'products.family_sort_order, products.name, products.id';

        $capturedMember = fn (string $column, string $filter): string => "(
            select products.{$column}
            from products
            left join categories on categories.id = products.category_id
            where {$publicVisibleMembers}
                and {$filter}
            order by {$order}
            limit 1
        )";

        DB::statement(sprintf(
            'update product_families set custom_emoji_id = %s, icon = coalesce(%s, %s)',
            $capturedMember('custom_emoji_id', $capturedEmoji),
            $capturedMember('icon', $capturedEmoji.' and '.$plainGlyph),
            $capturedMember('icon', $plainGlyph),
        ));
    }

    /**
     * A family capture used to copy its icon onto every member. Those copies are
     * exactly the family value now, so they must be released to inherit the
     * family icon instead of shadowing it as a per-Product override. A member
     * that kept a different glyph is a deliberate per-Product icon and stays.
     */
    private function releaseMaterialisedMemberIcons(): void
    {
        DB::statement("
            update products set icon = null, custom_emoji_id = null
            where products.product_family_id is not null
                and exists (
                    select 1 from product_families
                    where product_families.id = products.product_family_id
                        and coalesce(product_families.icon, '') = coalesce(products.icon, '')
                        and coalesce(product_families.custom_emoji_id, '') = coalesce(products.custom_emoji_id, '')
                        and (
                            coalesce(product_families.icon, '') <> ''
                            or coalesce(product_families.custom_emoji_id, '') <> ''
                        )
                )
        ");
    }

    /**
     * Rolling the feature back removes the family columns, so members that only
     * inherited the family icon receive a copy again and keep rendering.
     */
    private function materialiseMemberIcons(): void
    {
        DB::statement("
            update products
            set icon = (select product_families.icon from product_families where product_families.id = products.product_family_id),
                custom_emoji_id = (select product_families.custom_emoji_id from product_families where product_families.id = products.product_family_id)
            where products.product_family_id is not null
                and coalesce(trim(products.icon), '') = ''
                and coalesce(trim(products.custom_emoji_id), '') = ''
                and exists (
                    select 1 from product_families
                    where product_families.id = products.product_family_id
                        and (
                            coalesce(trim(product_families.icon), '') <> ''
                            or coalesce(trim(product_families.custom_emoji_id), '') <> ''
                        )
                )
        ");
    }
};
