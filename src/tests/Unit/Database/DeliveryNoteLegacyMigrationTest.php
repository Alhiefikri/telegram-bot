<?php

namespace Tests\Unit\Database;

use ReflectionMethod;
use Tests\TestCase;

class DeliveryNoteLegacyMigrationTest extends TestCase
{
    public function test_legacy_sections_move_to_delivery_note_without_losing_public_description(): void
    {
        $migration = require database_path('migrations/2026_07_12_000003_add_delivery_notes_to_products_and_order_items.php');
        $split = new ReflectionMethod($migration, 'splitLegacyDescription');

        [$description, $deliveryNote] = $split->invoke($migration, "Akses Canva siap pakai.\n\nInformasi tambahan publik.\n\nFitur utama:\n- Bonus setup\n- Garansi login\n\nPaket/spesifikasi:\n- Durasi 30 hari");

        $this->assertSame("Akses Canva siap pakai.\n\nInformasi tambahan publik.", $description);
        $this->assertSame("Fitur utama:\n- Bonus setup\n- Garansi login\n\nPaket/spesifikasi:\n- Durasi 30 hari", $deliveryNote);
    }
}
