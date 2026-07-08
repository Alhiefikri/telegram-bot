<?php

return [
    'meta' => [
        'title' => ':brand | Akun AI Premium Siap Pakai',
        'description' => 'Lhie Store menyediakan akun AI premium siap pakai dengan stok transparan, harga jelas, dan bantuan order lewat support Telegram.',
    ],

    'nav' => [
        ['label' => 'Produk', 'href' => '#products'],
        ['label' => 'Cara Beli', 'href' => '#cara-beli'],
        ['label' => 'FAQ', 'href' => '#faq'],
        ['label' => 'Support', 'href' => '#support'],
    ],

    'links' => [
        'telegram_bot_url' => env('STOREFRONT_TELEGRAM_BOT_URL', 'https://t.me/lhiestore_bot?start=landing'),
    ],

    'hero' => [
        'eyebrow' => 'Akun AI premium siap pakai',
        'headline' => 'Toolkit AI premium untuk kerja harian.',
        'body' => 'Pilih produk dari web, lalu bayar lewat QRIS website atau lanjutkan transaksi melalui bot/admin Telegram yang sudah berjalan.',
        'primary_cta' => 'Belanja Web',
        'secondary_cta' => 'Buka Bot Telegram',
        'tertiary_cta' => 'Lihat Produk',
        'popular_label' => 'Produk aktif dari database',
        'popular_empty' => 'Produk populer akan tampil setelah stok aktif tersedia.',
    ],

    'trust_indicators' => [
        [
            'title' => 'Stok real-time',
            'body' => 'Jumlah stok siap terlihat di setiap kartu produk.',
        ],
        [
            'title' => 'Akses cepat',
            'body' => 'Akun digital disiapkan setelah pembayaran terkonfirmasi.',
        ],
        [
            'title' => 'Bantuan Telegram',
            'body' => 'Support membantu cek stok, pembayaran, dan kendala akses.',
        ],
        [
            'title' => 'Harga jelas',
            'body' => 'Harga katalog tampil sebelum Anda menghubungi support.',
        ],
    ],

    'catalog' => [
        'eyebrow' => 'Katalog Lhie Store',
        'title' => 'Pilih akun AI sesuai kebutuhan.',
        'body' => 'Cari ChatGPT, Claude, Gemini, Canva, dan akun AI premium lain yang sedang tersedia. Produk aktif dari admin otomatis tampil di katalog ini.',
        'search_placeholder' => 'Cari akun AI...',
        'empty_title' => 'Katalog publik sedang disiapkan.',
        'empty_body' => 'Produk aktif akan tampil otomatis di sini setelah dipublikasikan dari admin. Sambil menunggu, pelanggan bisa menghubungi support untuk rekomendasi akun AI yang tersedia.',
        'empty_category_title' => 'Kategori akan segera hadir.',
        'empty_category_body' => 'Tim toko sedang menyiapkan kategori publik pertama agar katalog mudah dijelajahi.',
    ],

    'shop' => [
        'title' => 'Marketplace akun AI siap pakai',
        'body' => 'Pilih produk, pilih bayar QRIS dari web atau lanjut lewat Telegram.',
        'empty_title' => 'Belum ada produk siap dibeli.',
        'empty_body' => 'Produk aktif akan muncul otomatis setelah admin mempublikasikan stok.',
        'buyer_handle_label' => 'Username Telegram / nama pembeli',
        'buyer_handle_help' => 'Punya Telegram? isi @username. Tidak punya Telegram? isi nama panggilan.',
        'checkout_cta' => 'Bayar QRIS di Website',
        'telegram_cta' => 'Beli lewat Telegram',
    ],

    'featured' => [
        'eyebrow' => 'Pilihan hari ini',
        'empty_title' => 'Promo pilihan menunggu stok siap.',
        'empty_body' => 'Belum ada produk aktif dengan stok tersedia untuk dijadikan pilihan utama. Katalog tetap bisa dibaca, dan pelanggan dapat menghubungi support untuk rekomendasi.',
        'empty_cta' => 'Minta rekomendasi ke support',
    ],

    'benefits' => [
        [
            'title' => 'Akun siap pakai',
            'body' => 'Produk dikurasi untuk kebutuhan AI harian: menulis, coding, riset, desain, sampai konten sosial.',
        ],
        [
            'title' => 'Tanpa tebak stok',
            'body' => 'Status sisa stok dan kondisi habis tampil jelas sebelum Anda lanjut ke support.',
        ],
        [
            'title' => 'Order dibantu manusia',
            'body' => 'Support membantu memastikan pilihan produk, instruksi bayar, dan pengiriman akses tetap jelas.',
        ],
    ],

    'payment_flow' => [
        'eyebrow' => 'Cara beli',
        'title' => 'Belanja akun AI dalam tiga langkah.',
        'body' => 'Pilih produk dari katalog, pilih bayar QRIS website atau Telegram, lalu terima akses setelah pembayaran terkonfirmasi.',
        'steps' => [
            [
                'title' => 'Pilih produk',
                'body' => 'Cari akun AI yang dibutuhkan dan pastikan badge stok masih tersedia.',
            ],
            [
                'title' => 'Pilih jalur checkout',
                'body' => 'Gunakan QRIS website untuk bayar langsung, atau lanjut lewat bot/admin Telegram.',
            ],
            [
                'title' => 'Terima akses',
                'body' => 'Website menampilkan data akun setelah pembayaran terverifikasi; Telegram tetap berjalan untuk order dari bot.',
            ],
        ],
    ],

    'delivery' => [
        'eyebrow' => 'Pengiriman digital',
        'title' => 'Akses dikirim setelah pembayaran terkonfirmasi.',
        'body' => 'Produk digital disiapkan setelah status pembayaran valid dan stok tervalidasi. Jika ada antrean verifikasi manual, support akan membantu memberi status order melalui kanal yang tersedia.',
    ],

    'qris' => [
        'eyebrow' => 'QRIS Website',
        'title' => 'QRIS web dibuat per order',
        'body' => 'Di jalur website, QRIS final muncul di halaman invoice setelah checkout. Jalur Telegram tetap mengikuti instruksi bot/admin.',
    ],

    'telegram_support' => [
        'eyebrow' => 'Support',
        'title' => 'Bot dan admin Telegram membantu status order.',
        'body' => 'Telegram dipakai untuk notifikasi dan bantuan order yang sudah berjalan. Halaman ini hanya menjelaskan kanal bantuan; perilaku bot dan webhook Telegram tidak berubah.',
    ],

    'testimonial' => [
        'quote' => 'Butuh akun AI cepat untuk kerja harian. Di Lhie Store, stoknya jelas dan proses tanyanya tidak ribet.',
        'name' => 'Pelanggan Lhie Store',
        'role' => 'Pengguna akun AI premium',
        'stats' => [
            ['value' => '24 jam', 'label' => 'support order'],
            ['value' => 'Real-time', 'label' => 'status stok katalog'],
            ['value' => 'Digital', 'label' => 'pengiriman akses'],
        ],
    ],

    'faq' => [
        [
            'question' => 'Bagaimana cara membeli akun AI di Lhie Store?',
            'answer' => 'Pilih produk dari katalog, hubungi support melalui tombol yang tersedia, lalu ikuti instruksi pembayaran. Setelah pembayaran terkonfirmasi, akses atau instruksi produk digital akan dikirim melalui kanal yang tersedia.',
        ],
        [
            'question' => 'Apakah stok yang tampil benar-benar tersedia?',
            'answer' => 'Katalog menampilkan stok siap berdasarkan data produk aktif di admin. Jika stok habis, kartu produk akan berubah menjadi status tanya stok agar pelanggan tidak mengira produk siap dibeli.',
        ],
        [
            'question' => 'Produk apa saja yang tersedia?',
            'answer' => 'Produk dapat berubah mengikuti stok toko. Katalog biasanya berisi akun AI premium dan produk digital pendukung seperti ChatGPT, Claude, Gemini, Canva, atau layanan serupa yang dipublikasikan admin.',
        ],
        [
            'question' => 'Bagaimana jika pesanan saya bermasalah?',
            'answer' => 'Hubungi support dengan detail order. Tim support akan membantu pengecekan status, stok, pembayaran, or kendala teknis produk digital.',
        ],
        [
            'question' => 'Apakah bot Telegram memproses order dari halaman ini?',
            'answer' => 'Order website diproses sebagai order web dengan QRIS dan invoice bertanda tangan. Bot Telegram tetap berjalan untuk pelanggan yang memilih jalur Telegram.',
        ],
    ],

    'support' => [
        'eyebrow' => 'Siap belanja?',
        'title' => 'Butuh bantuan setelah checkout?',
        'body' => 'Gunakan katalog sementara ini untuk memilih produk, lalu hubungi support Lhie Store untuk cek detail stok, pembayaran, dan pengiriman akses digital.',
        'primary_cta' => 'Chat Support',
        'secondary_cta' => 'Login Admin',
    ],

    'footer' => [
        'body' => 'Lhie Store membantu pelanggan menemukan akun AI premium siap pakai dengan katalog ringan, stok jelas, dan support yang mudah dihubungi.',
        'platform_title' => 'Toko',
        'help_title' => 'Bantuan',
        'copyright_suffix' => 'Lhie Store. Semua hak dilindungi.',
        'powered_by' => 'Dibangun untuk katalog akun AI premium.',
    ],

    'login_guide' => [
        'images' => [
            'email' => 'storefront/login-guide/chatgpt-email-step.png',
            'password' => 'storefront/login-guide/chatgpt-password-step.png',
            'authenticatorSetup' => 'storefront/login-guide/google-authenticator-setup.png',
            'authenticatorCode' => 'storefront/login-guide/google-authenticator-code.png',
            'mfaCode' => 'storefront/login-guide/chatgpt-mfa-code-step.png',
            'security' => 'storefront/login-guide/chatgpt-security-mfa.png',
        ],
        'prep_items' => [
            ['label' => 'Email login', 'body' => 'Alamat Outlook atau iCloud yang dikirim oleh bot. Salin persis tanpa spasi.'],
            ['label' => 'Password', 'body' => 'Password email/akun. Perhatikan huruf besar, angka, dan simbol.'],
            ['label' => 'MFA Secret', 'body' => 'Kunci penyandingan untuk ditambahkan ke Google Authenticator. Ini bukan kode 6 digit.'],
            ['label' => 'Google Authenticator', 'body' => 'Aplikasi HP yang menghasilkan kode 6 digit untuk verifikasi ChatGPT.'],
        ],
        'quick_flow' => [
            'Email Outlook/iCloud',
            'Password akun',
            'Tambah MFA Secret',
            'Masukkan kode 6 digit',
            'Ganti password + MFA',
        ],
        'steps' => [
            [
                'number' => '01',
                'kicker' => 'Login ChatGPT',
                'title' => 'Masukkan email Outlook/iCloud di kolom email.',
                'image' => 'email',
                'alt' => 'Screenshot halaman Log in or sign up ChatGPT dengan kolom email dan tombol Continue ditandai',
                'caption' => 'Pilih jalur email manual. Jangan memakai Continue with Google, Apple, atau phone kecuali data dari bot memang meminta itu.',
                'body' => 'Buka halaman ChatGPT, pilih Log in atau Sign up, lalu isi alamat email yang diberikan. Setelah email benar, tekan Continue.',
                'points' => [
                    'Gunakan email yang dikirim bot, misalnya nama@outlook.com atau nama@icloud.com.',
                    'Pastikan tidak ada spasi di depan/belakang email saat menempelkan teks.',
                    'Tekan Continue satu kali dan tunggu halaman password muncul.',
                ],
                'watch' => 'Kalau tidak bisa lanjut, biasanya email salah ketik, domain salah, atau ada spasi tersembunyi.',
            ],
            [
                'number' => '02',
                'kicker' => 'Password',
                'title' => 'Isi password persis seperti data yang dikirim.',
                'image' => 'password',
                'alt' => 'Screenshot halaman password ChatGPT dengan email, password, dan tombol Continue ditandai',
                'caption' => 'Urutannya: cek email di atas, isi password, lalu Continue.',
                'body' => 'Halaman berikutnya meminta password akun. Masukkan password dengan teliti karena huruf besar/kecil, angka, dan simbol berpengaruh.',
                'points' => [
                    'Cocokkan email yang tampil di bagian atas halaman password.',
                    'Isi password tanpa mengubah karakter apa pun.',
                    'Gunakan tombol lihat password jika tersedia sebelum menekan Continue.',
                ],
                'watch' => 'Jangan menebak terlalu banyak kali. Jika gagal, ulangi dari salin data awal agar akun tidak terkena pembatasan sementara.',
            ],
            [
                'number' => '03',
                'kicker' => 'Google Authenticator',
                'title' => 'Tambahkan MFA Secret ke Google Authenticator.',
                'image' => 'authenticatorSetup',
                'alt' => 'Screenshot Google Authenticator untuk memasukkan nama kode, MFA Secret, jenis berbasis waktu, dan tombol Tambahkan',
                'caption' => 'MFA Secret ditempel di kolom Kunci Anda. Jenis kunci harus Berbasis waktu.',
                'body' => 'Buka Google Authenticator di HP, tekan tombol tambah, lalu pilih opsi memasukkan kunci penyiapan/kode penyandingan.',
                'points' => [
                    'Isi Nama kode, misalnya ChatGPT atau Lhie Store agar mudah dikenali.',
                    'Tempel MFA Secret ke kolom Kunci Anda.',
                    'Pastikan Jenis kunci = Berbasis waktu, lalu tekan Tambahkan.',
                ],
                'watch' => 'MFA Secret hanya dipakai di Google Authenticator. Jangan memasukkan MFA Secret ke halaman ChatGPT.',
            ],
            [
                'number' => '04',
                'kicker' => 'Kode MFA',
                'title' => 'Ambil kode 6 digit terbaru dari Authenticator.',
                'image' => 'authenticatorCode',
                'alt' => 'Contoh kartu Google Authenticator yang menampilkan kode 6 digit berubah otomatis',
                'caption' => 'Kode 6 digit berubah otomatis setiap beberapa detik. Pakai kode yang waktunya masih cukup.',
                'body' => 'Setelah MFA Secret berhasil ditambahkan, Google Authenticator akan menampilkan kode 6 digit. Inilah kode yang dipakai untuk verifikasi login.',
                'points' => [
                    'Cari nama kode yang baru dibuat di Google Authenticator.',
                    'Jika timer hampir habis, tunggu kode baru agar tidak ditolak.',
                    'Salin atau ketik 6 digit angka saja, tanpa spasi dan tanpa teks tambahan.',
                ],
                'watch' => 'Kode 6 digit berbeda dari MFA Secret. Kode ini sementara; MFA Secret adalah kunci penyandingan.',
            ],
            [
                'number' => '05',
                'kicker' => 'Verifikasi ChatGPT',
                'title' => 'Masukkan kode 6 digit ke halaman verifikasi.',
                'image' => 'mfaCode',
                'alt' => 'Screenshot halaman Verify your identity ChatGPT dengan kolom kode 6 digit dan tombol Continue ditandai',
                'caption' => 'Isi kolom One-time code dengan kode terbaru, lalu tekan Continue.',
                'body' => 'Kembali ke halaman ChatGPT yang meminta one-time password, masukkan kode 6 digit dari Google Authenticator, kemudian tekan Continue.',
                'points' => [
                    'Masukkan angka terbaru dari Authenticator, bukan kode lama.',
                    'Tekan Continue setelah semua 6 digit terisi.',
                    'Jika ditolak, tunggu kode baru dan ulangi tanpa mengubah MFA Secret.',
                ],
                'watch' => 'Kode yang sudah kedaluwarsa sering terlihat benar, tetapi tetap ditolak. Tunggu siklus kode baru jika ragu.',
            ],
            [
                'number' => '06',
                'kicker' => 'Keamanan akun',
                'title' => 'Setelah masuk, ganti password dan atur ulang MFA.',
                'image' => 'security',
                'alt' => 'Screenshot menu Security and login dengan password dan Authenticator app ditandai',
                'caption' => 'Prioritas setelah berhasil masuk: password baru dulu, lalu MFA baru di perangkat kamu.',
                'body' => 'Login berhasil belum selesai. Amankan akun agar aksesnya berada di perangkat kamu sendiri dan tidak bergantung pada data awal dari bot.',
                'points' => [
                    'Buka menu Security and login.',
                    'Ganti password dengan password baru yang kuat.',
                    'Masuk ke Multi-factor authentication, tambah MFA baru, lalu hapus metode lama jika yang baru sudah aktif.',
                ],
                'watch' => 'Simpan password dan metode MFA baru di tempat aman. Jangan kirim password/MFA ke ruang publik.',
            ],
        ],
        'troubleshooting' => [
            ['title' => 'Email tidak lanjut', 'body' => 'Cek typo, domain email, dan spasi tersembunyi. Salin ulang dari data bot bila perlu.'],
            ['title' => 'Password salah', 'body' => 'Periksa huruf besar/kecil, simbol, angka, dan jangan menambahkan spasi saat menempel.'],
            ['title' => 'Kode MFA ditolak', 'body' => 'Gunakan kode 6 digit terbaru dari Google Authenticator, bukan MFA Secret dan bukan kode lama.'],
            ['title' => 'Kode tidak muncul', 'body' => 'Tambahkan ulang MFA Secret di Google Authenticator dengan jenis kunci Berbasis waktu.'],
        ],
        'security_checklist' => [
            'Password sudah diganti dengan password baru.',
            'MFA/Authenticator sudah aktif di perangkat kamu sendiri.',
            'Kode pemulihan atau data penting sudah disimpan dengan aman.',
            'Akun sudah logout dari perangkat yang tidak digunakan.',
        ],
    ],
];
