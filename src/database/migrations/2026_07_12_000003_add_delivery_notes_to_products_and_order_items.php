<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('delivery_note')->nullable()->after('description');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->text('delivery_note')->nullable()->after('product_name');
            $table->boolean('delivery_note_snapshotted')->default(false)->after('delivery_note');
        });

        DB::table('products')->orderBy('id')->each(function (object $product): void {
            [$description, $deliveryNote] = $this->splitLegacyDescription((string) $product->description);

            if ($deliveryNote === null) {
                return;
            }

            DB::table('products')->where('id', $product->id)->update([
                'description' => $description,
                'delivery_note' => $deliveryNote,
                'updated_at' => now(),
            ]);
        }, 100);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['delivery_note', 'delivery_note_snapshotted']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('delivery_note');
        });
    }

    /** @return array{0: string, 1: ?string} */
    private function splitLegacyDescription(string $description): array
    {
        $sections = preg_split("/\n{2,}/", trim($description)) ?: [];
        $publicSections = [];
        $deliverySections = [];

        foreach ($sections as $section) {
            $section = trim($section);
            if ($section === '') {
                continue;
            }

            if (str_starts_with($section, 'Fitur utama:') || str_starts_with($section, 'Paket/spesifikasi:')) {
                $deliverySections[] = $section;
            } else {
                $publicSections[] = $section;
            }
        }

        return [implode("\n\n", $publicSections), $deliverySections === [] ? null : implode("\n\n", $deliverySections)];
    }
};
