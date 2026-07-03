@php
    $guideImages = collect(range(1, 9))->mapWithKeys(fn (int $page): array => [
        $page => asset("storefront/login-guide/page-{$page}.jpg"),
    ]);

    $steps = [
        [
            'kicker' => 'Langkah 0',
            'title' => 'Siapkan data login dulu',
            'image' => 2,
            'body' => 'Sebelum membuka ChatGPT, pastikan email login, password, MFA Secret, dan aplikasi Google Authenticator sudah siap. Ini mencegah buyer salah memasukkan MFA Secret sebagai kode 6 digit.',
            'points' => [
                'Email Outlook atau iCloud yang dikirim oleh bot.',
                'Password email, termasuk huruf besar, angka, dan simbolnya.',
                'MFA Secret atau kode penyandingan untuk ditambahkan ke Google Authenticator.',
                'Aplikasi Google Authenticator di HP buyer.',
            ],
            'note' => 'MFA Secret bukan kode 6 digit. Secret dipakai untuk membuat kode 6 digit di Google Authenticator.',
        ],
        [
            'kicker' => 'Langkah 1',
            'title' => 'Masukkan email Outlook atau iCloud',
            'image' => 3,
            'body' => 'Di halaman login ChatGPT, pilih kolom email lalu masukkan alamat email persis seperti yang dikirim. Jangan menambah spasi di depan atau belakang email.',
            'points' => [
                'Buka ChatGPT dan pilih Log in atau Sign up.',
                'Isi email Outlook/iCloud di kolom email.',
                'Klik Continue untuk lanjut ke halaman password.',
            ],
            'note' => 'Kalau email tidak lanjut, cek ulang format seperti nama@outlook.com atau nama@icloud.com.',
        ],
        [
            'kicker' => 'Langkah 2',
            'title' => 'Masukkan password akun',
            'image' => 4,
            'body' => 'Setelah email diterima, ChatGPT akan meminta password. Isi password sesuai data yang dikirim, lalu lanjutkan ke proses verifikasi MFA.',
            'points' => [
                'Pastikan email yang tampil di halaman sudah benar.',
                'Masukkan password dengan teliti.',
                'Gunakan tombol lihat password jika tersedia.',
                'Klik Continue untuk membuka halaman verifikasi.',
            ],
            'note' => 'Password biasanya sensitif huruf besar/kecil. Jika gagal, jangan menebak terlalu banyak kali.',
        ],
        [
            'kicker' => 'Langkah 3',
            'title' => 'Tambahkan MFA di Google Authenticator',
            'image' => 5,
            'body' => 'Buka Google Authenticator, tekan tombol tambah, lalu pilih opsi memasukkan kunci penyiapan. Tempel MFA Secret dari bot ke kolom kunci.',
            'points' => [
                'Isi nama kode, misalnya ChatGPT atau Lhie Store.',
                'Tempel MFA Secret pada kolom kunci.',
                'Pastikan jenis kunci berbasis waktu.',
                'Tekan Tambahkan agar kode 6 digit muncul.',
            ],
            'note' => 'Jangan kirim MFA Secret ke halaman ChatGPT. Yang dipakai untuk ChatGPT adalah kode 6 digit yang muncul setelah secret ditambahkan.',
        ],
        [
            'kicker' => 'Langkah 4',
            'title' => 'Masukkan kode MFA 6 digit',
            'image' => 6,
            'body' => 'Ambil kode 6 digit terbaru dari Google Authenticator, lalu masukkan ke halaman verifikasi ChatGPT. Kode ini berubah otomatis secara berkala.',
            'points' => [
                'Buka Google Authenticator dan cari kode yang baru dibuat.',
                'Jika timer hampir habis, tunggu kode baru.',
                'Masukkan 6 digit angka ke halaman ChatGPT.',
                'Klik Continue untuk menyelesaikan login.',
            ],
            'note' => 'Jika kode ditolak, ulangi dengan kode terbaru. Jangan gunakan kode lama atau MFA Secret.',
        ],
        [
            'kicker' => 'Langkah 5',
            'title' => 'Amankan akun setelah berhasil masuk',
            'image' => 7,
            'body' => 'Setelah berhasil masuk, segera ganti password dan atur ulang MFA agar akses akun berada di perangkat buyer sendiri.',
            'points' => [
                'Buka menu Security and login.',
                'Ganti password dengan password baru yang kuat.',
                'Masuk ke Multi-factor authentication.',
                'Tambahkan MFA baru di perangkat buyer.',
                'Hapus metode lama hanya setelah MFA baru aktif.',
            ],
            'note' => 'Prioritasnya: ganti password dulu, aktifkan MFA baru, lalu simpan data baru di tempat aman.',
        ],
        [
            'kicker' => 'Langkah 6',
            'title' => 'Kalau login gagal, cek dari sini',
            'image' => 8,
            'body' => 'Gunakan checklist ini sebelum mencoba berulang-ulang. Terlalu banyak percobaan salah bisa membuat akun terkena pembatasan sementara.',
            'points' => [
                'Email tidak lanjut: pastikan tidak ada typo atau spasi.',
                'Password salah: cek huruf besar/kecil, angka, dan simbol.',
                'Kode MFA ditolak: gunakan kode 6 digit terbaru.',
                'Kode tidak muncul: tambahkan ulang MFA Secret di Google Authenticator.',
            ],
            'note' => 'Jika tetap gagal, hubungi support dan kirim invoice plus screenshot kendala tanpa membagikan password ke ruang publik.',
        ],
    ];

    $securityChecklist = [
        'Password sudah diganti dengan password baru.',
        'MFA sudah aktif di perangkat buyer sendiri.',
        'Kode pemulihan atau data penting sudah disimpan aman.',
        'Akun sudah logout dari perangkat yang tidak digunakan.',
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)] motion-reduce:animate-none">
    <div class="storefront-shell min-h-screen">
        <header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-6xl rounded-2xl bg-white/92 backdrop-blur-xl border border-[var(--sf-border)] shadow-sm">
            <div class="flex min-h-14 items-center justify-between gap-4 px-5 py-3">
                <a href="{{ route('storefront.home') }}" class="flex items-center gap-2">
                    <div class="h-7 w-7 rounded-lg bg-slate-900 flex items-center justify-center text-white font-black text-sm select-none shadow-sm">L</div>
                    <span class="text-sm font-bold tracking-tight text-[var(--sf-ink)]">{{ $brand }}</span>
                </a>

                <nav class="hidden md:flex items-center gap-1.5 p-1 bg-slate-50 rounded-xl text-xs font-semibold text-slate-600 border border-slate-200">
                    <a href="{{ route('storefront.home') }}#products" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Produk</a>
                    <a href="#langkah" class="px-4 py-1.5 rounded-lg bg-white text-slate-900 shadow-sm">Panduan</a>
                    <a href="#troubleshooting" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Bantuan</a>
                    <a href="{{ $supportDestination }}" class="px-4 py-1.5 rounded-lg hover:bg-white hover:text-slate-900 transition-colors">Support</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-5 py-12 sm:px-6 lg:py-18">
            <section class="grid gap-10 lg:grid-cols-[0.92fr_1.08fr] lg:items-center">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.24em] text-slate-500">Panduan setelah pembelian</p>
                    <h1 class="mt-4 max-w-3xl text-3xl sm:text-5xl lg:text-6xl font-black tracking-tight text-slate-950 leading-[1.02] text-balance">
                        Cara masuk dan amankan akun digital kamu.
                    </h1>
                    <p class="mt-5 max-w-2xl text-base text-slate-600 leading-relaxed">
                        Ikuti panduan ini setelah bot mengirim data akun. Urutannya dibuat untuk akun ChatGPT yang memakai email Outlook/iCloud dan MFA Google Authenticator: masuk dengan email, verifikasi kode, lalu amankan akun supaya aksesnya ada di perangkat kamu.
                    </p>

                    <div class="mt-8 grid gap-3 sm:grid-cols-3">
                        <a href="#langkah" class="inline-flex h-12 items-center justify-center rounded-xl bg-slate-900 px-5 text-sm font-bold text-white hover:bg-slate-700 active:scale-[0.98] transition-all">
                            Mulai panduan
                        </a>
                        <a href="#checklist" class="inline-flex h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-bold text-slate-900 hover:border-slate-400 active:scale-[0.98] transition-all">
                            Cek keamanan
                        </a>
                        <a href="{{ $supportDestination }}" class="inline-flex h-12 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-5 text-sm font-bold text-slate-700 hover:bg-white active:scale-[0.98] transition-all">
                            Chat support
                        </a>
                    </div>
                </div>

                <figure class="relative">
                    <div class="absolute -left-5 top-8 hidden h-28 w-28 rounded-full bg-slate-200/70 blur-2xl lg:block"></div>
                    <img
                        src="{{ $guideImages[1] }}"
                        alt="Cover panduan login ChatGPT memakai email Outlook atau iCloud dan MFA Google Authenticator"
                        class="relative w-full rounded-2xl border border-slate-200 bg-white shadow-[0_24px_70px_rgba(15,23,42,0.16)]"
                        loading="eager"
                    >
                </figure>
            </section>

            <section class="mt-14 grid gap-5 md:grid-cols-3" aria-label="Ringkasan alur login">
                <div class="rounded-2xl bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-slate-950">1. Masuk akun</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">Gunakan email Outlook/iCloud dan password yang dikirim oleh bot.</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-slate-950">2. Verifikasi MFA</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">Tambahkan MFA Secret ke Google Authenticator, lalu pakai kode 6 digit terbaru.</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-slate-950">3. Amankan akses</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">Ganti password dan pindahkan MFA ke perangkat buyer setelah berhasil login.</p>
                </div>
            </section>

            <section id="langkah" class="mt-20 space-y-16 scroll-mt-28">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[0.24em] text-slate-500">Langkah lengkap</p>
                    <h2 class="mt-3 text-2xl sm:text-4xl font-black tracking-tight text-slate-950">Ikuti dari atas, jangan lompat langkah.</h2>
                    <p class="mt-4 text-sm sm:text-base leading-relaxed text-slate-600">Setiap bagian di bawah mengikuti gambar dari panduan asli. Baca catatan singkatnya, lalu cocokkan dengan screenshot agar tidak salah membedakan email, password, MFA Secret, dan kode MFA.</p>
                </div>

                @foreach ($steps as $index => $step)
                    <article class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-start {{ $index % 2 === 1 ? 'lg:[&>figure]:order-2' : '' }}">
                        <div class="lg:sticky lg:top-28">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{{ $step['kicker'] }}</p>
                            <h3 class="mt-3 text-2xl sm:text-3xl font-black tracking-tight text-slate-950">{{ $step['title'] }}</h3>
                            <p class="mt-4 text-sm sm:text-base leading-relaxed text-slate-600">{{ $step['body'] }}</p>

                            <ul class="mt-6 space-y-3">
                                @foreach ($step['points'] as $point)
                                    <li class="flex gap-3 text-sm leading-relaxed text-slate-700">
                                        <span class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-slate-900 text-[10px] font-bold text-white">{{ $loop->iteration }}</span>
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <p class="mt-6 rounded-2xl bg-slate-100 p-4 text-sm font-semibold leading-relaxed text-slate-800">{{ $step['note'] }}</p>
                        </div>

                        <figure>
                            <img
                                src="{{ $guideImages[$step['image']] }}"
                                alt="{{ $step['kicker'] }} - {{ $step['title'] }}"
                                class="w-full rounded-2xl border border-slate-200 bg-white shadow-[0_18px_55px_rgba(15,23,42,0.12)]"
                                loading="lazy"
                            >
                        </figure>
                    </article>
                @endforeach
            </section>

            <section id="troubleshooting" class="mt-20 grid gap-8 rounded-3xl bg-white p-6 shadow-sm md:grid-cols-[0.9fr_1.1fr] md:p-8 scroll-mt-28">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.24em] text-slate-500">Troubleshooting</p>
                    <h2 class="mt-3 text-2xl sm:text-4xl font-black tracking-tight text-slate-950">Kalau masih gagal, cek empat penyebab paling sering.</h2>
                    <p class="mt-4 text-sm sm:text-base leading-relaxed text-slate-600">Jangan langsung mengulang login berkali-kali. Periksa satu per satu agar akun tidak terkena pembatasan sementara.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl bg-slate-50 p-5">
                        <p class="text-sm font-bold text-slate-950">Email tidak lanjut</p>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">Cek typo, domain email, dan spasi tersembunyi di awal/akhir.</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-5">
                        <p class="text-sm font-bold text-slate-950">Password salah</p>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">Periksa huruf besar/kecil, simbol, dan angka. Gunakan tombol lihat password jika ada.</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-5">
                        <p class="text-sm font-bold text-slate-950">Kode MFA ditolak</p>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">Pakai kode 6 digit terbaru dari Google Authenticator, bukan MFA Secret.</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-5">
                        <p class="text-sm font-bold text-slate-950">Kode tidak muncul</p>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">Tambahkan ulang MFA Secret di Google Authenticator dengan tipe berbasis waktu.</p>
                    </div>
                </div>
            </section>

            <section id="checklist" class="mt-10 grid gap-8 rounded-3xl bg-slate-950 p-6 text-white md:grid-cols-[1fr_0.9fr] md:p-8 scroll-mt-28">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.24em] text-slate-300">Checklist keamanan</p>
                    <h2 class="mt-3 text-2xl sm:text-4xl font-black tracking-tight">Selesai login bukan berarti selesai mengamankan akun.</h2>
                    <p class="mt-4 max-w-xl text-sm sm:text-base leading-relaxed text-slate-300">Sebelum meninggalkan halaman ini, pastikan semua poin ini sudah aman. Kalau ada satu yang belum beres, selesaikan dulu atau hubungi support.</p>
                    <ul class="mt-7 space-y-3">
                        @foreach ($securityChecklist as $item)
                            <li class="flex gap-3 text-sm leading-relaxed text-slate-100">
                                <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-400"></span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <figure>
                    <img
                        src="{{ $guideImages[9] }}"
                        alt="Halaman selesai panduan login akun dan pengingat menyimpan password serta MFA baru"
                        class="w-full rounded-2xl border border-white/10 bg-white shadow-[0_18px_55px_rgba(0,0,0,0.25)]"
                        loading="lazy"
                    >
                </figure>
            </section>
        </main>
    </div>
</body>
</html>
