<?php

return [
    'meta' => [
        'title' => ':brand - Toko Produk Digital Instan',
        'description' => 'Toko produk digital dengan katalog kurasi, pembayaran jelas, dan dukungan Telegram untuk bantuan order.',
    ],

    'hero' => [
        'eyebrow' => 'Storefront publik untuk produk digital pilihan',
        'headline' => 'Beli produk digital dengan alur sederhana dan support yang jelas.',
        'body' => 'Jelajahi katalog, cek stok dan harga, lalu lanjutkan ke kanal pembelian yang tersedia tanpa menebak langkah berikutnya.',
        'primary_cta' => 'Lihat produk',
        'secondary_cta' => 'Butuh bantuan?',
    ],

    'benefits' => [
        [
            'title' => 'Produk digital siap pakai',
            'body' => 'Katalog difokuskan pada kebutuhan produktivitas, akun digital, dan akses layanan yang praktis.',
        ],
        [
            'title' => 'Pembayaran jelas',
            'body' => 'Instruksi pembayaran ditampilkan sebelum konfirmasi sehingga pelanggan tahu nominal, metode, dan batas waktunya.',
        ],
        [
            'title' => 'Bantuan lewat Telegram',
            'body' => 'Support order tetap dekat dengan kanal Telegram yang sudah dipakai operasional toko.',
        ],
    ],

    'payment_flow' => [
        'eyebrow' => 'Alur pembayaran',
        'title' => 'Bayar setelah detail order jelas.',
        'body' => 'Pelanggan memilih produk, mengecek ketersediaan, lalu mengikuti instruksi pembayaran yang diberikan pada kanal checkout atau support. Pembayaran diproses sesuai metode yang tersedia tanpa mengubah webhook pembayaran.',
        'steps' => [
            'Pilih produk dan pastikan stok masih tersedia.',
            'Terima instruksi nominal, metode, dan batas waktu pembayaran.',
            'Setelah pembayaran terkonfirmasi, order masuk ke proses pengiriman digital.',
        ],
    ],

    'delivery' => [
        'eyebrow' => 'Pengiriman digital',
        'title' => 'Akses dikirim setelah pembayaran terkonfirmasi.',
        'body' => 'Produk digital disiapkan setelah status pembayaran valid dan stok tervalidasi. Jika ada antrean verifikasi manual, support akan membantu memberi status order melalui kanal yang tersedia.',
    ],

    'telegram_support' => [
        'eyebrow' => 'Telegram support',
        'title' => 'Bot dan admin Telegram membantu status order.',
        'body' => 'Telegram dipakai untuk notifikasi dan bantuan order yang sudah berjalan. Halaman ini hanya menjelaskan kanal bantuan; perilaku bot dan webhook Telegram tidak berubah.',
    ],

    'trust_indicators' => [
        [
            'title' => 'Pembayaran aman',
            'body' => 'Instruksi pembayaran jelas dan konfirmasi mengikuti status order.',
        ],
        [
            'title' => 'Pengiriman cepat',
            'body' => 'Produk digital diproses setelah pembayaran valid dan stok siap.',
        ],
        [
            'title' => 'Support Telegram',
            'body' => 'Bantuan order tersedia lewat kanal Telegram toko.',
        ],
        [
            'title' => 'Stok transparan',
            'body' => 'Kartu produk menampilkan stok siap atau status habis sebelum pelanggan bertanya.',
        ],
    ],

    'faq' => [
        [
            'question' => 'Metode pembayaran apa yang tersedia?',
            'answer' => 'Metode pembayaran mengikuti instruksi order yang diberikan saat checkout atau oleh support. Nominal dan batas waktu dikirim sebelum pelanggan membayar.',
        ],
        [
            'question' => 'Kapan produk digital dikirim?',
            'answer' => 'Produk digital dikirim setelah pembayaran terkonfirmasi dan stok tersedia. Support akan membantu jika order memerlukan verifikasi manual.',
        ],
        [
            'question' => 'Bagaimana jika stok habis?',
            'answer' => 'Produk yang stoknya habis ditandai di katalog. Pelanggan bisa menanyakan estimasi restock atau alternatif produk lewat support Telegram.',
        ],
        [
            'question' => 'Apakah bot Telegram memproses order dari halaman ini?',
            'answer' => 'Tidak. Halaman ini hanya menampilkan informasi publik. Bot Telegram dan webhook pembayaran tetap berjalan di alur operasional yang sudah ada.',
        ],
        [
            'question' => 'Apakah admin panel masih tersedia?',
            'answer' => 'Ya. Operator tetap bisa masuk melalui tautan Admin tanpa mengganggu pengalaman pelanggan.',
        ],
    ],

    'support' => [
        'eyebrow' => 'Support',
        'title' => 'Butuh rekomendasi produk atau bantuan order?',
        'body' => 'Gunakan kanal support toko untuk bertanya tentang stok, pembayaran, dan pengiriman produk digital. Admin panel tetap tersedia hanya untuk operator.',
    ],
];
