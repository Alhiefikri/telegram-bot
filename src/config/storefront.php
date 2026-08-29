<?php

return [
    'locale' => env('STOREFRONT_LOCALE', 'id'),

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

    'product_placeholder' => '/assets/product-placeholder.svg',
    'sitemap' => [
        'max_urls' => (int) env('STOREFRONT_SITEMAP_MAX_URLS', 1000),
    ],

    'guide_catalog' => [
        [
            'eyebrow' => 'ChatGPT',
            'title' => 'Panduan login ChatGPT',
            'description' => 'Masuk memakai email Outlook atau iCloud, gunakan kode MFA, lalu amankan akun setelah login.',
            'methods' => ['Outlook', 'iCloud', 'Google Authenticator'],
            'route' => 'storefront.login-guide',
        ],
    ],

    'hero' => [
        'eyebrow' => 'Produk digital & layanan premium siap pakai',
        'headline' => 'Produk digital siap pakai, checkout tanpa ribet.',
        'body' => 'Pilih produk, cek stok, lalu bayar QRIS langsung di website.',
        'primary_cta' => 'Lihat Produk',
        'secondary_cta' => 'Cara beli',
        'tertiary_cta' => 'Lihat Produk',
        'popular_label' => 'Produk terlaris berdasarkan penjualan berbayar',
        'popular_empty' => 'Belum ada data penjualan berbayar selesai untuk menampilkan label produk terlaris.',
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
            'body' => 'Untuk pesanan website, invoice dan Recovery Key membantu membuka kembali data pesanan.',
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
        'title' => 'Katalog produk digital siap pakai',
        'body' => 'Pilih produk, bayar QRIS di website, lalu simpan invoice dan Recovery Key khusus pesanan website untuk akses ulang.',
        'empty_title' => 'Belum ada produk siap dibeli.',
        'empty_body' => 'Produk aktif akan muncul otomatis setelah admin mempublikasikan stok.',
        'buyer_name_label' => 'Nama pembeli',
        'buyer_name_help' => 'Checkout tidak perlu akun. Nama ini hanya dipakai untuk menandai invoice Anda.',
        'support_contact_label' => 'Kontak bantuan opsional',
        'support_contact_help' => 'Diisi hanya jika Anda ingin support punya kanal follow-up. Kontak ini bukan bukti kepemilikan pesanan; akses tetap memakai invoice dan Recovery Key.',
        'email_label' => 'Email untuk notifikasi (opsional)',
        'email_help' => 'Dengan mengisi email, Anda menyetujui notifikasi transaksional tentang pesanan ini. Email bukan bukti kepemilikan; akses tetap mengikuti kebijakan pesanan.',
        'terms_consent_help' => 'Persetujuan ini disimpan bersama order agar kebijakan yang berlaku saat checkout tetap tercatat.',
        'checkout_cta' => 'Bayar QRIS di Website',
    ],

    'featured' => [
        'eyebrow' => 'Pilihan utama dari admin',
        'empty_title' => 'Belum ada pilihan utama.',
        'empty_body' => 'Admin belum memilih produk aktif dengan stok tersedia untuk dijadikan pilihan utama. Katalog tetap bisa dibaca dan diperiksa lagi saat stok diperbarui.',
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
            'body' => 'Untuk pesanan website, nomor invoice dan Recovery Key memberi akses ulang tanpa perlu membuat akun toko.',
        ],
    ],

    'payment_flow' => [
        'eyebrow' => 'Cara beli',
        'title' => 'Belanja produk digital dalam tiga langkah.',
        'body' => 'Pilih produk dari katalog, checkout sebagai tamu di website, lalu simpan invoice dan Recovery Key untuk akses ulang pesanan website.',
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
                'body' => 'Website menampilkan data akun setelah pembayaran terverifikasi. Simpan invoice dan Recovery Key untuk membuka kembali pesanan website; pesanan Telegram tidak memakai key ini.',
            ],
        ],
    ],

    'delivery' => [
        'eyebrow' => 'Pengiriman digital',
        'title' => 'Akses dikirim setelah pembayaran terkonfirmasi.',
        'body' => 'Produk digital disiapkan setelah status pembayaran valid dan stok tervalidasi. Jika ada antrean verifikasi manual, support akan membantu memberi status order melalui kanal yang tersedia.',
    ],

    'fulfillment' => [
        'automatic_default_instruction' => 'Login dari perangkat pribadi, ganti password setelah berhasil masuk, lalu pindahkan MFA/Authenticator ke perangkat Anda sendiri jika produk mendukungnya.',
        'manual' => [
            'processing_title' => 'Pembayaran diterima, menunggu diproses',
            'completed_title' => 'Fulfillment selesai',
            'received_body' => 'Invoice sudah lunas dan aman diproses.',
            'processing_body' => 'Admin akan menyelesaikan produk manual memakai invoice ini.',
            'processing_done_body' => 'Tahap proses manual sudah dilewati.',
            'completed_waiting_body' => 'Status akan berubah setelah admin menandai fulfillment selesai.',
            'completed_body' => 'Produk manual ditandai selesai oleh admin.',
            'next_step' => 'Produk ini tidak punya kredensial otomatis di invoice. Gunakan tombol bantuan di bawah jika data akun belum muncul, kredensial tidak valid, MFA bermasalah, atau login gagal.',
        ],
        'credentials_unavailable' => [
            'title' => 'Data akun belum tersedia',
            'body' => 'Pembayaran sudah diterima, tetapi kredensial otomatis belum tersedia di invoice ini. Gunakan bantuan kategori Data akun belum muncul agar admin mengecek invoice tanpa meminta secret.',
        ],
    ],

    'qris' => [
        'eyebrow' => 'QRIS Website',
        'title' => 'QRIS web dibuat per order',
        'body' => 'QRIS final muncul di halaman invoice setelah checkout. Simpan invoice dan Recovery Key sebelum melanjutkan pembayaran; key ini hanya untuk pesanan website.',
    ],

    'recovery_access' => [
        'eyebrow' => 'Akses ulang',
        'title' => 'Invoice dan Recovery Key untuk pesanan website.',
        'body' => 'Gunakan halaman Akses Pesanan bila ingin membuka kembali data akun dari pesanan website tanpa membuat akun Lhie Store. Pesanan Telegram dicek lewat bot.',
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
            'answer' => 'Pilih produk dari katalog, checkout sebagai tamu di website, lalu bayar QRIS dari halaman invoice. Setelah pembayaran terkonfirmasi, buka data produk dengan nomor invoice dan Recovery Key yang Anda simpan saat checkout website.',
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
            'answer' => 'Untuk pesanan website, buka Akses Pesanan menggunakan nomor invoice dan Recovery Key. Pesanan Telegram dicek lewat bot. Pastikan pembayaran sudah terverifikasi dan ikuti panduan login yang tersedia sebelum mencoba akun. Support hanya mengecek status dan masalah login, bukan membuka kredensial dari invoice saja.',
        ],
        [
            'question' => 'Bagaimana cara membuka data pesanan lagi?',
            'answer' => 'Untuk pesanan website, buka halaman Akses Pesanan lalu masukkan nomor invoice dan Recovery Key yang Anda simpan saat checkout. Recovery Key tidak dipakai untuk pesanan Telegram, tidak dapat ditampilkan ulang jika hilang, dan tidak dapat diganti oleh support.',
        ],
    ],

    'support' => [
        'eyebrow' => 'Siap belanja?',
        'title' => 'Butuh membuka pesanan lagi?',
        'body' => 'Untuk pesanan website, gunakan nomor invoice dan Recovery Key yang Anda simpan saat checkout untuk membuka kembali data akun setelah pembayaran terverifikasi. Pesanan Telegram tidak memakai Recovery Key.',
        'primary_cta' => 'Akses Pesanan',
        'secondary_cta' => 'Login Admin',
    ],

    'policies' => [
        'privacy' => [
            'eyebrow' => 'Privasi',
            'title' => 'Kebijakan Privasi',
            'description' => 'Cara Lhie Store memakai data checkout, invoice, pembayaran, akun pelanggan, dan support.',
            'updated' => '20 Juli 2026',
            'sections' => [
                [
                    'title' => 'Data yang kami pakai',
                    'items' => [
                        'Nama pembeli, email pelanggan bila Anda membuat akun, nomor invoice, item pesanan, status pembayaran, dan catatan operasional yang dibutuhkan untuk memenuhi pesanan.',
                        'Data pembayaran yang diterima dari penyedia QRIS atau pembayaran lain, seperti status transaksi, nominal, referensi pembayaran, dan waktu konfirmasi.',
                        'Pesan support yang Anda kirim melalui WhatsApp atau Telegram admin untuk membantu verifikasi masalah pesanan.',
                    ],
                ],
                [
                    'title' => 'Data rahasia pelanggan',
                    'items' => [
                        'Recovery Key hanya ditampilkan satu kali kepada pelanggan dan hanya hash-nya yang disimpan.',
                        'Magic-link token, Recovery Key, password, MFA secret, dan kredensial akun tidak boleh dicatat ke log, audit metadata, atau pesan support.',
                        'Support tidak dapat membaca, mengirim ulang, atau menebak Recovery Key dari sistem.',
                    ],
                ],
                [
                    'title' => 'Pemakaian dan penyimpanan',
                    'body' => 'Data digunakan untuk membuat invoice, memverifikasi pembayaran, mengirim produk digital, menyediakan akses pesanan, menangani support, audit operasional, keamanan, dan kewajiban pembukuan. Data yang tidak lagi dibutuhkan dapat dibersihkan mengikuti jadwal retensi operasional.',
                ],
            ],
        ],
        'terms' => [
            'eyebrow' => 'Syarat',
            'title' => 'Syarat Penggunaan',
            'description' => 'Aturan transaksi produk digital, akses pesanan, support, dan batas tanggung jawab Lhie Store.',
            'version' => env('STOREFRONT_TERMS_VERSION', '2026-07-20'),
            'updated' => '20 Juli 2026',
            'sections' => [
                [
                    'title' => 'Transaksi produk digital',
                    'items' => [
                        'Pelanggan wajib memastikan produk, jumlah, dan harga sebelum checkout.',
                        'Invoice website harus dibayar sesuai total final yang tampil di halaman invoice.',
                        'Produk digital dikirim atau dibuka setelah pembayaran terverifikasi dan stok tersedia.',
                    ],
                ],
                [
                    'title' => 'Akses pesanan',
                    'items' => [
                        'Pesanan website dapat dibuka kembali dengan nomor invoice dan Recovery Key yang valid.',
                        'Nomor invoice saja tidak cukup untuk membuka kredensial atau memindahkan kepemilikan pesanan.',
                        'Pelanggan bertanggung jawab menyimpan invoice, Recovery Key, dan kredensial di tempat pribadi yang aman.',
                    ],
                ],
                [
                    'title' => 'Batas kewenangan support',
                    'body' => 'Support boleh membantu mengecek status invoice, status pembayaran, dan kategori masalah. Support tidak boleh memberikan Recovery Key, password, MFA secret, credential bundle, atau membuka kredensial hanya berdasarkan nomor invoice, nama pembeli, username Telegram, atau email.',
                ],
            ],
        ],
        'refund' => [
            'eyebrow' => 'Refund',
            'title' => 'Kebijakan Refund',
            'description' => 'Kapan pesanan produk digital dapat dibatalkan, dikembalikan, atau harus ditangani sebagai support.',
            'updated' => '20 Juli 2026',
            'sections' => [
                [
                    'title' => 'Order belum dibayar',
                    'body' => 'Order pending yang belum dibayar dapat dibatalkan dari halaman invoice selama tombol pembatalan masih tersedia. Jika invoice sudah expired atau dibatalkan, jangan lakukan pembayaran dan buat order baru bila masih ingin membeli.',
                ],
                [
                    'title' => 'Pembayaran gagal atau expired',
                    'body' => 'Pembayaran yang gagal, expired, salah nominal, atau tidak terdeteksi harus dicek melalui support dengan nomor invoice dan bukti pembayaran bila ada. Support hanya memverifikasi status pembayaran, bukan membuka kredensial dari invoice saja.',
                ],
                [
                    'title' => 'Produk belum dikirim',
                    'body' => 'Jika pembayaran sudah valid tetapi produk belum bisa dikirim karena stok, gangguan sistem, atau kesalahan operasional, Lhie Store dapat mengganti produk setara, menunggu restock dengan persetujuan pelanggan, atau memproses refund sesuai hasil audit order.',
                ],
                [
                    'title' => 'Kredensial sudah dibuka',
                    'body' => 'Produk digital yang kredensialnya sudah tampil atau sudah dikirim tidak dapat direfund karena akses sudah terbuka, kecuali terbukti kredensial tidak valid sejak awal dan pelanggan melapor segera untuk pemeriksaan.',
                ],
            ],
        ],
        'recovery' => [
            'eyebrow' => 'Pemulihan',
            'title' => 'Pemulihan Pesanan',
            'description' => 'Aturan pemulihan invoice, Recovery Key, kepemilikan order, dan data yang tidak boleh diberikan support.',
            'updated' => '20 Juli 2026',
            'sections' => [
                [
                    'title' => 'Kebijakan utama',
                    'body' => 'Order website yang kehilangan Recovery Key tidak dapat dipulihkan secara manual untuk membuka kredensial. Sistem hanya menyimpan hash Recovery Key, sehingga key asli tidak dapat dibaca atau dikirim ulang. Nomor invoice saja tidak pernah cukup untuk membuka credential bundle.',
                ],
                [
                    'title' => 'Bukti kepemilikan yang dapat dicek',
                    'items' => [
                        'Nomor invoice dan Recovery Key yang valid untuk akses mandiri.',
                        'Akun pelanggan yang sudah memiliki order paid, atau klaim order guest dengan invoice dan Recovery Key yang valid.',
                        'Bukti pembayaran dapat membantu investigasi status pembayaran, tetapi tidak menggantikan Recovery Key untuk membuka kredensial.',
                    ],
                ],
                [
                    'title' => 'Pelaksana dan audit trail',
                    'body' => 'Support lini pertama hanya mengumpulkan konteks masalah dan bukti pembayaran yang relevan. Admin atau pemilik produk dapat memeriksa status order, pembayaran, stok, dan audit log. Setiap perubahan manual pada status order, stok, atau follow-up pelanggan harus meninggalkan audit trail internal.',
                ],
                [
                    'title' => 'Data yang tetap tidak boleh diberikan',
                    'items' => [
                        'Recovery Key, password, MFA secret, token login, dan credential bundle tidak boleh diberikan melalui support.',
                        'Kredensial tidak boleh dibuka berdasarkan invoice, nama pembeli, email, username Telegram, atau screenshot invoice saja.',
                        'Support tidak boleh meminta pelanggan mengirim Recovery Key ke chat publik atau grup.',
                    ],
                ],
            ],
        ],
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
