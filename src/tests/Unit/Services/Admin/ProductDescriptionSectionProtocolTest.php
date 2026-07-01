<?php

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\ProductDescriptionSectionProtocol;
use PHPUnit\Framework\TestCase;

class ProductDescriptionSectionProtocolTest extends TestCase
{
    public function test_it_encodes_description_sections_in_persisted_format(): void
    {
        $protocol = new ProductDescriptionSectionProtocol;

        $description = $protocol->encode(
            '  Akses Canva Pro siap pakai.  ',
            " Akun ready \n\n Garansi login ",
            "Durasi 30 hari\n Support reset",
        );

        $this->assertSame(
            "Akses Canva Pro siap pakai.\n\nFitur utama:\n- Akun ready\n- Garansi login\n\nPaket/spesifikasi:\n- Durasi 30 hari\n- Support reset",
            $description,
        );
    }

    public function test_it_omits_empty_optional_sections_when_encoding(): void
    {
        $protocol = new ProductDescriptionSectionProtocol;

        $this->assertSame(
            'Produk tanpa fitur tambahan.',
            $protocol->encode(' Produk tanpa fitur tambahan. ', "\n ", ''),
        );
    }

    public function test_it_decodes_persisted_description_sections_for_edit_fields(): void
    {
        $protocol = new ProductDescriptionSectionProtocol;

        $sections = $protocol->decode(
            "Akses streaming siap pakai.\n\nFitur utama:\n- Garansi login\n- Bisa semua device\n\nPaket/spesifikasi:\n- Durasi 30 hari\n- Support reset",
        );

        $this->assertSame([
            'description' => 'Akses streaming siap pakai.',
            'features' => "Garansi login\nBisa semua device",
            'specifications' => "Durasi 30 hari\nSupport reset",
        ], $sections);
    }
}
