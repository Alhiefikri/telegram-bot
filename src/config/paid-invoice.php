<?php

return [
    'font_path' => env('PAID_INVOICE_FONT_PATH', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
    'font_bold_path' => env('PAID_INVOICE_FONT_BOLD_PATH', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'),
    'font_cjk_path' => env('PAID_INVOICE_FONT_CJK_PATH', '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'),
    'font_arabic_path' => env('PAID_INVOICE_FONT_ARABIC_PATH', '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf'),
    'font_devanagari_path' => env('PAID_INVOICE_FONT_DEVANAGARI_PATH', '/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf'),
    'font_bengali_path' => env('PAID_INVOICE_FONT_BENGALI_PATH', '/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf'),
    'logo_path' => env('PAID_INVOICE_LOGO_PATH', resource_path('images/lhie-store-logo.png')),
];
