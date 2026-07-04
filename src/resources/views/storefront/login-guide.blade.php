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
        <header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-6xl rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[6px_6px_0_var(--sf-shadow)]">
            <div class="flex min-h-16 items-center justify-between gap-4 px-5 py-3">
                <a href="{{ route('storefront.home') }}" class="flex items-center gap-2">
                    @include('storefront.components.brand-mark', ['brand' => $brand])
                </a>

                <nav class="hidden items-center gap-1.5 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] p-1 text-xs font-black text-[var(--sf-ink)] md:flex">
                    <a href="{{ route('storefront.home') }}#products" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Produk</a>
                    <a href="#langkah" class="rounded-md bg-[var(--sf-surface)] px-4 py-2 shadow-[3px_3px_0_var(--sf-shadow)]">Panduan</a>
                    <a href="#troubleshooting" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Bantuan</a>
                    <a href="{{ $supportDestination }}" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Support</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-5 py-12 sm:px-6 lg:py-18">
            <section class="grid gap-10 lg:grid-cols-[0.92fr_1.08fr] lg:items-center">
                <div>
                    <p class="neo-chip inline-flex px-4 py-2 text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-ink)]">Panduan setelah pembelian</p>
                    <h1 class="mt-5 max-w-3xl font-display text-4xl font-black leading-[0.98] tracking-tight text-[var(--sf-ink)] text-balance sm:text-6xl lg:text-7xl">
                        Cara masuk dan amankan akun digital kamu.
                    </h1>
                    <p class="mt-5 max-w-2xl text-base font-semibold leading-relaxed text-[var(--sf-muted)]">
                        Ikuti panduan ini setelah bot mengirim data akun. Urutannya dibuat untuk akun ChatGPT yang memakai email Outlook/iCloud dan MFA Google Authenticator: masuk dengan email, verifikasi kode, lalu amankan akun supaya aksesnya ada di perangkat kamu.
                    </p>

                    <div class="mt-8 grid gap-3 sm:grid-cols-3">
                        <a href="#langkah" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">
                            Mulai panduan
                        </a>
                        <a href="#checklist" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-surface)] px-5 text-sm font-black text-[var(--sf-ink)]">
                            Cek keamanan
                        </a>
                        <a href="{{ $supportDestination }}" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-yellow)] px-5 text-sm font-black text-[var(--sf-ink)]">
                            Chat support
                        </a>
                    </div>
                </div>

                <figure class="relative">
                    <div class="absolute -left-5 top-8 hidden h-20 w-20 rotate-12 border-[3px] border-[var(--sf-border)] bg-[var(--sf-blue)] shadow-[6px_6px_0_var(--sf-shadow)] lg:block"></div>
                    <img
                        src="{{ $guideImages[1] }}"
                        alt="Cover panduan login ChatGPT memakai email Outlook atau iCloud dan MFA Google Authenticator"
                        class="neo-panel relative w-full bg-[var(--sf-surface)]"
                        loading="eager"
                    >
                </figure>
            </section>

            <section class="mt-14 grid gap-5 md:grid-cols-3" aria-label="Ringkasan alur login">
                <div class="neo-panel-tight bg-[var(--sf-surface)] p-5">
                    <p class="font-display text-sm font-black text-[var(--sf-ink)]">1. Masuk akun</p>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Gunakan email Outlook/iCloud dan password yang dikirim oleh bot.</p>
                </div>
                <div class="neo-panel-tight bg-[var(--sf-yellow)] p-5">
                    <p class="font-display text-sm font-black text-[var(--sf-ink)]">2. Verifikasi MFA</p>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Tambahkan MFA Secret ke Google Authenticator, lalu pakai kode 6 digit terbaru.</p>
                </div>
                <div class="neo-panel-tight bg-[var(--sf-blue)] p-5 text-white">
                    <p class="font-display text-sm font-black">3. Amankan akses</p>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-white/85">Ganti password dan pindahkan MFA ke perangkat buyer setelah berhasil login.</p>
                </div>
            </section>

            <section id="langkah" class="mt-20 space-y-16 scroll-mt-28">
                <div class="max-w-2xl">
                    <p class="neo-chip inline-flex px-4 py-2 text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-ink)]">Langkah lengkap</p>
                    <h2 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">Ikuti dari atas, jangan lompat langkah.</h2>
                    <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Setiap bagian di bawah mengikuti gambar dari panduan asli. Baca catatan singkatnya, lalu cocokkan dengan screenshot agar tidak salah membedakan email, password, MFA Secret, dan kode MFA.</p>
                </div>

                @foreach ($steps as $index => $step)
                    <article class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-start {{ $index % 2 === 1 ? 'lg:[&>figure]:order-2' : '' }}">
                        <div class="lg:sticky lg:top-28">
                            <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--sf-accent)]">{{ $step['kicker'] }}</p>
                            <h3 class="mt-3 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">{{ $step['title'] }}</h3>
                            <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">{{ $step['body'] }}</p>

                            <ul class="mt-6 space-y-3">
                                @foreach ($step['points'] as $point)
                                    <li class="flex gap-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                                        <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] text-[10px] font-black text-[var(--sf-ink)] shadow-[2px_2px_0_var(--sf-shadow)]">{{ $loop->iteration }}</span>
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <p class="neo-panel-tight mt-6 bg-[var(--sf-surface)] p-4 text-sm font-bold leading-relaxed text-[var(--sf-ink)]">{{ $step['note'] }}</p>
                        </div>

                        <figure>
                            <img
                                src="{{ $guideImages[$step['image']] }}"
                                alt="{{ $step['kicker'] }} - {{ $step['title'] }}"
                                class="neo-panel w-full bg-[var(--sf-surface)]"
                                loading="lazy"
                            >
                        </figure>
                    </article>
                @endforeach
            </section>

            <section id="troubleshooting" class="neo-panel mt-20 grid scroll-mt-28 gap-8 bg-[var(--sf-surface)] p-6 md:grid-cols-[0.9fr_1.1fr] md:p-8">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-[var(--sf-accent)]">Troubleshooting</p>
                    <h2 class="mt-3 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Kalau masih gagal, cek empat penyebab paling sering.</h2>
                    <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Jangan langsung mengulang login berkali-kali. Periksa satu per satu agar akun tidak terkena pembatasan sementara.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="neo-panel-tight bg-[#fff8dc] p-5">
                        <p class="font-display text-sm font-black text-[var(--sf-ink)]">Email tidak lanjut</p>
                        <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Cek typo, domain email, dan spasi tersembunyi di awal/akhir.</p>
                    </div>
                    <div class="neo-panel-tight bg-[#fff8dc] p-5">
                        <p class="font-display text-sm font-black text-[var(--sf-ink)]">Password salah</p>
                        <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Periksa huruf besar/kecil, simbol, dan angka. Gunakan tombol lihat password jika ada.</p>
                    </div>
                    <div class="neo-panel-tight bg-[#fff8dc] p-5">
                        <p class="font-display text-sm font-black text-[var(--sf-ink)]">Kode MFA ditolak</p>
                        <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Pakai kode 6 digit terbaru dari Google Authenticator, bukan MFA Secret.</p>
                    </div>
                    <div class="neo-panel-tight bg-[#fff8dc] p-5">
                        <p class="font-display text-sm font-black text-[var(--sf-ink)]">Kode tidak muncul</p>
                        <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Tambahkan ulang MFA Secret di Google Authenticator dengan tipe berbasis waktu.</p>
                    </div>
                </div>
            </section>

            <section id="checklist" class="neo-panel mt-10 grid scroll-mt-28 gap-8 bg-[var(--sf-accent)] p-6 text-white md:grid-cols-[1fr_0.9fr] md:p-8">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-white/80">Checklist keamanan</p>
                    <h2 class="mt-3 font-display text-2xl font-black tracking-tight sm:text-4xl">Selesai login bukan berarti selesai mengamankan akun.</h2>
                    <p class="mt-4 max-w-xl text-sm font-semibold leading-relaxed text-white/85 sm:text-base">Sebelum meninggalkan halaman ini, pastikan semua poin ini sudah aman. Kalau ada satu yang belum beres, selesaikan dulu atau hubungi support.</p>
                    <ul class="mt-7 space-y-3">
                        @foreach ($securityChecklist as $item)
                            <li class="flex gap-3 text-sm font-semibold leading-relaxed text-white">
                                <span class="mt-1 h-3 w-3 shrink-0 rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)]"></span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <figure>
                    <img
                        src="{{ $guideImages[9] }}"
                        alt="Halaman selesai panduan login akun dan pengingat menyimpan password serta MFA baru"
                        class="w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white shadow-[6px_6px_0_var(--sf-shadow)]"
                        loading="lazy"
                    >
                </figure>
            </section>
        </main>
    </div>
</body>
</html>
