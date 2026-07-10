<?php

return [
    'meta' => [
        'title' => ':brand | Akun Premium & Produk Digital',
        'description' => 'Lhie Store menyediakan produk digital dan akun premium dengan stok transparan, harga jelas, checkout QRIS, serta akses pesanan mandiri.',
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
        'eyebrow' => 'Produk digital & layanan premium siap pakai',
        'headline' => 'Produk digital siap pakai, checkout tanpa ribet.',
        'body' => 'Pilih produk, cek stok, lalu bayar QRIS langsung di website.',
        'primary_cta' => 'Lihat Produk',
        'secondary_cta' => 'Cara beli',
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
            'title' => 'Akses mandiri',
            'body' => 'Invoice dan recovery key membantu membuka kembali data pesanan.',
        ],
        [
            'title' => 'Harga jelas',
            'body' => 'Harga katalog tampil jelas sebelum Anda checkout.',
        ],
    ],

    'catalog' => [
        'eyebrow' => 'Katalog Lhie Store',
        'title' => 'Pilih produk digital sesuai kebutuhan.',
        'body' => 'Cari akun premium, lisensi software, tools digital, dan produk digital lain yang sedang tersedia. Produk aktif otomatis tampil di katalog ini.',
        'search_placeholder' => 'Cari produk digital...',
        'empty_title' => 'Katalog publik sedang disiapkan.',
        'empty_body' => 'Produk aktif akan tampil otomatis di sini setelah dipublikasikan dari admin. Cek kembali katalog saat stok produk yang Anda cari tersedia.',
        'empty_category_title' => 'Kategori akan segera hadir.',
        'empty_category_body' => 'Tim toko sedang menyiapkan kategori publik pertama agar katalog mudah dijelajahi.',
    ],

    'shop' => [
        'title' => 'Marketplace produk digital siap pakai',
        'body' => 'Pilih produk, bayar QRIS di website, lalu simpan invoice dan recovery key untuk akses ulang.',
        'empty_title' => 'Belum ada produk siap dibeli.',
        'empty_body' => 'Produk aktif akan muncul otomatis setelah admin mempublikasikan stok.',
        'buyer_handle_label' => 'Nama pembeli',
        'buyer_handle_help' => 'Checkout tidak perlu akun. Nama ini hanya dipakai untuk menandai invoice Anda.',
        'checkout_cta' => 'Bayar QRIS di Website',
    ],

    'featured' => [
        'eyebrow' => 'Pilihan hari ini',
        'empty_title' => 'Promo pilihan menunggu stok siap.',
        'empty_body' => 'Belum ada produk aktif dengan stok tersedia untuk dijadikan pilihan utama. Katalog tetap bisa dibaca dan diperiksa lagi saat stok diperbarui.',
        'empty_cta' => 'Minta rekomendasi ke support',
    ],

    'benefits' => [
        [
            'title' => 'Produk siap pakai',
            'body' => 'Produk dikurasi untuk berbagai kebutuhan digital: akun premium, lisensi software, game keys, dan tools harian.',
        ],
        [
            'title' => 'Tanpa tebak stok',
            'body' => 'Status sisa stok dan kondisi habis tampil jelas sebelum Anda lanjut ke support.',
        ],
        [
            'title' => 'Akses mandiri',
            'body' => 'Nomor invoice dan recovery key memberi akses ulang tanpa perlu membuat akun toko.',
        ],
    ],

    'payment_flow' => [
        'eyebrow' => 'Cara beli',
        'title' => 'Belanja produk digital dalam tiga langkah.',
        'body' => 'Pilih produk dari katalog, checkout sebagai tamu, lalu simpan invoice dan recovery key untuk akses ulang.',
        'steps' => [
            [
                'title' => 'Pilih produk',
                'body' => 'Cari produk digital yang dibutuhkan dan pastikan badge stok masih tersedia.',
            ],
            [
                'title' => 'Checkout sebagai tamu',
                'body' => 'Tidak perlu membuat akun. Isi nama pembeli, lalu buat QRIS langsung dari website.',
            ],
            [
                'title' => 'Terima akses',
                'body' => 'Website menampilkan data akun setelah pembayaran terverifikasi. Simpan invoice dan recovery key untuk membuka kembali data akun.',
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
        'body' => 'QRIS final muncul di halaman invoice setelah checkout. Simpan invoice dan recovery key sebelum melanjutkan pembayaran.',
    ],

    'recovery_access' => [
        'eyebrow' => 'Akses ulang',
        'title' => 'Invoice dan recovery key menjaga akses pesanan.',
        'body' => 'Gunakan halaman Akses Pesanan bila ingin membuka kembali data akun tanpa membuat akun Lhie Store.',
    ],

    'testimonial' => [
        'quote' => 'Butuh produk digital cepat untuk kebutuhan harian. Di Lhie Store, stoknya jelas dan proses tanyanya tidak ribet.',
        'name' => 'Pelanggan Lhie Store',
        'role' => 'Pelanggan Lhie Store',
        'stats' => [
            ['value' => 'Mandiri', 'label' => 'akses pesanan'],
            ['value' => 'Real-time', 'label' => 'status stok katalog'],
            ['value' => 'Digital', 'label' => 'pengiriman akses'],
        ],
    ],

    'faq' => [
        [
            'question' => 'Bagaimana cara membeli produk digital di Lhie Store?',
            'answer' => 'Pilih produk dari katalog, checkout sebagai tamu, lalu bayar QRIS dari halaman invoice. Setelah pembayaran terkonfirmasi, buka data produk dengan nomor invoice dan recovery key yang Anda simpan saat checkout.',
        ],
        [
            'question' => 'Apakah saya harus login untuk membeli?',
            'answer' => 'Tidak. Checkout website memakai nama pembeli untuk menandai invoice. Login hanya diperlukan nanti jika produk yang dibeli memang memiliki akun layanan tersendiri.',
        ],
        [
            'question' => 'Apakah stok yang tampil benar-benar tersedia?',
            'answer' => 'Katalog menampilkan stok siap berdasarkan data produk aktif di admin. Jika stok habis, kartu produk akan berubah menjadi status tanya stok agar pelanggan tidak mengira produk siap dibeli.',
        ],
        [
            'question' => 'Produk apa saja yang tersedia?',
            'answer' => 'Produk dapat berubah mengikuti stok toko. Katalog biasanya berisi akun premium, lisensi software, game keys, dan produk digital lain yang dipublikasikan admin.',
        ],
        [
            'question' => 'Bagaimana jika pesanan saya bermasalah?',
            'answer' => 'Buka Akses Pesanan menggunakan nomor invoice dan recovery key. Pastikan pembayaran sudah terverifikasi dan ikuti panduan login yang tersedia sebelum mencoba akun.',
        ],
        [
            'question' => 'Bagaimana cara membuka data pesanan lagi?',
            'answer' => 'Buka halaman Akses Pesanan, lalu masukkan nomor invoice dan recovery key yang Anda simpan saat checkout. Recovery key tidak dapat ditampilkan ulang jika hilang.',
        ],
    ],

    'support' => [
        'eyebrow' => 'Siap belanja?',
        'title' => 'Butuh membuka pesanan lagi?',
        'body' => 'Gunakan nomor invoice dan recovery key yang Anda simpan saat checkout untuk membuka kembali data akun setelah pembayaran terverifikasi.',
        'primary_cta' => 'Akses Pesanan',
        'secondary_cta' => 'Login Admin',
    ],

    'footer' => [
        'body' => 'Lhie Store membantu pelanggan menemukan produk digital dan akun premium siap pakai dengan katalog ringan, stok jelas, dan akses pesanan mandiri.',
        'platform_title' => 'Toko',
        'help_title' => 'Bantuan',
        'copyright_suffix' => 'Lhie Store. Semua hak dilindungi.',
        'powered_by' => 'Dibangun untuk katalog produk digital premium.',
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
