@php
    $guideImages = [
        'email' => asset('storefront/login-guide/chatgpt-email-step.png'),
        'password' => asset('storefront/login-guide/chatgpt-password-step.png'),
        'authenticatorSetup' => asset('storefront/login-guide/google-authenticator-setup.png'),
        'authenticatorCode' => asset('storefront/login-guide/google-authenticator-code.png'),
        'mfaCode' => asset('storefront/login-guide/chatgpt-mfa-code-step.png'),
        'security' => asset('storefront/login-guide/chatgpt-security-mfa.png'),
    ];

    $prepItems = [
        ['label' => 'Email login', 'body' => 'Alamat Outlook atau iCloud yang dikirim oleh bot. Salin persis tanpa spasi.'],
        ['label' => 'Password', 'body' => 'Password email/akun. Perhatikan huruf besar, angka, dan simbol.'],
        ['label' => 'MFA Secret', 'body' => 'Kunci penyandingan untuk ditambahkan ke Google Authenticator. Ini bukan kode 6 digit.'],
        ['label' => 'Google Authenticator', 'body' => 'Aplikasi HP yang menghasilkan kode 6 digit untuk verifikasi ChatGPT.'],
    ];

    $quickFlow = [
        'Email Outlook/iCloud',
        'Password akun',
        'Tambah MFA Secret',
        'Masukkan kode 6 digit',
        'Ganti password + MFA',
    ];

    $steps = [
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
    ];

    $troubleshooting = [
        ['title' => 'Email tidak lanjut', 'body' => 'Cek typo, domain email, dan spasi tersembunyi. Salin ulang dari data bot bila perlu.'],
        ['title' => 'Password salah', 'body' => 'Periksa huruf besar/kecil, simbol, angka, dan jangan menambahkan spasi saat menempel.'],
        ['title' => 'Kode MFA ditolak', 'body' => 'Gunakan kode 6 digit terbaru dari Google Authenticator, bukan MFA Secret dan bukan kode lama.'],
        ['title' => 'Kode tidak muncul', 'body' => 'Tambahkan ulang MFA Secret di Google Authenticator dengan jenis kunci Berbasis waktu.'],
    ];

    $securityChecklist = [
        'Password sudah diganti dengan password baru.',
        'MFA/Authenticator sudah aktif di perangkat kamu sendiri.',
        'Kode pemulihan atau data penting sudah disimpan dengan aman.',
        'Akun sudah logout dari perangkat yang tidak digunakan.',
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white motion-reduce:animate-none">
    <div class="storefront-shell min-h-screen">
        <header class="sticky top-4 z-50 mx-auto w-[calc(100%-2rem)] max-w-6xl rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] shadow-[6px_6px_0_var(--sf-shadow)]">
            <div class="flex min-h-16 items-center justify-between gap-4 px-5 py-3">
                <a href="{{ route('storefront.home') }}" class="flex items-center gap-2" aria-label="Kembali ke beranda {{ $brand }}">
                    @include('storefront.components.brand-mark', ['brand' => $brand])
                </a>

                <nav class="hidden items-center gap-1.5 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] p-1 text-xs font-black text-[var(--sf-ink)] md:flex" aria-label="Navigasi panduan login">
                    <a href="{{ route('storefront.home') }}#products" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Produk</a>
                    <a href="#alur" class="rounded-md bg-[var(--sf-surface)] px-4 py-2 shadow-[3px_3px_0_var(--sf-shadow)]">Alur</a>
                    <a href="#langkah" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Panduan</a>
                    <a href="#bantuan" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Bantuan</a>
                    <a href="{{ $supportDestination }}" data-telegram-link data-icon="telegram" class="inline-flex items-center gap-1.5 rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">@include('storefront.components.telegram-icon', ['class' => 'h-3.5 w-3.5 shrink-0']) <span>Support</span></a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-5 py-12 sm:px-6 lg:py-18">
            <section class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                <div>
                    <p class="neo-chip inline-flex px-4 py-2 text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-ink)]">Panduan setelah pembelian</p>
                    <h1 class="mt-5 max-w-3xl font-display text-4xl font-black leading-[0.98] tracking-tight text-[var(--sf-ink)] text-balance sm:text-6xl lg:text-7xl">
                        Cara login ChatGPT pakai Outlook/iCloud + MFA.
                    </h1>
                    <p class="mt-5 max-w-2xl text-base font-semibold leading-relaxed text-[var(--sf-muted)]">
                        Versi ini memakai gambar asli yang diekstrak dari PDF panduan, bukan screenshot penuh halaman PDF. Ikuti urutan email → password → Google Authenticator → kode MFA → pengamanan akun.
                    </p>

                    <div class="mt-8 grid gap-3 sm:grid-cols-3">
                        <a href="#alur" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">
                            Mulai dari alur
                        </a>
                        <a href="#langkah" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-surface)] px-5 text-sm font-black text-[var(--sf-ink)]">
                            Lihat langkah
                        </a>
                        <a href="{{ $supportDestination }}" data-telegram-link data-icon="telegram" class="neo-button inline-flex h-12 items-center justify-center gap-2 bg-[var(--sf-yellow)] px-5 text-sm font-black text-[var(--sf-ink)]">
                            @include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0'])
                            <span>Chat support</span>
                        </a>
                    </div>
                </div>

                <figure class="neo-panel relative overflow-hidden bg-[#0b1220] p-3 text-white">
                    <div class="absolute right-4 top-4 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Visual guide</div>
                    <div class="grid gap-3 pt-10 sm:grid-cols-2">
                        <img src="{{ $guideImages['email'] }}" alt="Contoh kolom email ChatGPT" class="aspect-[4/3] h-full w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-cover object-bottom shadow-[5px_5px_0_var(--sf-shadow)]" loading="eager">
                        <img src="{{ $guideImages['password'] }}" alt="Contoh halaman password ChatGPT" class="aspect-[4/3] h-full w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-cover object-top shadow-[5px_5px_0_var(--sf-shadow)]" loading="eager">
                        <img src="{{ $guideImages['authenticatorCode'] }}" alt="Contoh kode 6 digit Google Authenticator" class="max-h-52 w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-contain shadow-[5px_5px_0_var(--sf-shadow)] sm:col-span-2" loading="eager">
                    </div>
                    <figcaption class="mt-4 border-t-2 border-dashed border-white/30 pt-3 text-xs font-bold leading-relaxed text-white/80">
                        Gambar dipotong dari screenshot panduan agar fokus ke bagian yang harus diklik/diisi.
                    </figcaption>
                </figure>
            </section>

            <section id="alur" class="neo-panel mt-14 scroll-mt-28 bg-[var(--sf-surface)] p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-5">
                    <div class="max-w-2xl">
                        <p class="text-xs font-black uppercase tracking-[0.24em] text-[var(--sf-accent)]">Alur cepat</p>
                        <h2 class="mt-3 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Jangan loncat. Login MFA harus berurutan.</h2>
                        <p class="mt-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Kalau satu tahap dilewati, ChatGPT biasanya menolak kode atau mengembalikan kamu ke halaman login.</p>
                    </div>
                    <a href="#checklist" class="neo-button inline-flex h-11 items-center justify-center bg-[var(--sf-green)] px-4 text-xs font-black text-[var(--sf-ink)]">Checklist akhir</a>
                </div>

                <ol class="mt-6 grid gap-3 md:grid-cols-5">
                    @foreach ($quickFlow as $flow)
                        <li class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 shadow-[3px_3px_0_var(--sf-shadow)]">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] text-xs font-black text-[var(--sf-ink)]">{{ $loop->iteration }}</span>
                            <p class="mt-3 text-sm font-black leading-snug text-[var(--sf-ink)]">{{ $flow }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="mt-10 grid gap-4 md:grid-cols-4" aria-label="Siapkan data login dulu">
                <div class="md:col-span-4">
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-[var(--sf-accent)]">Data login yang harus disiapkan</p>
                    <p class="mt-2 max-w-2xl text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Empat data ini harus sudah siap sebelum membuka halaman login agar proses email, password, dan MFA tidak bolak-balik.</p>
                </div>
                @foreach ($prepItems as $item)
                    <article class="neo-panel-tight bg-[var(--sf-surface)] p-5">
                        <p class="font-display text-sm font-black text-[var(--sf-ink)]">{{ $item['label'] }}</p>
                        <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $item['body'] }}</p>
                    </article>
                @endforeach
            </section>

            <section id="langkah" class="mt-20 space-y-14 scroll-mt-28">
                <div class="max-w-2xl">
                    <p class="neo-chip inline-flex px-4 py-2 text-xs font-black uppercase tracking-[0.18em] text-[var(--sf-ink)]">Langkah lengkap</p>
                    <h2 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">Ikuti bagian teks, lalu cocokkan dengan gambar.</h2>
                    <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Setiap screenshot hanya menampilkan bagian penting: kolom yang diisi, tombol yang ditekan, dan area keamanan yang harus dicek setelah login berhasil.</p>
                </div>

                @foreach ($steps as $index => $step)
                    <article class="grid gap-7 lg:grid-cols-[0.88fr_1.12fr] lg:items-start {{ $index % 2 === 1 ? 'lg:[&>figure]:order-2' : '' }}">
                        <div class="lg:sticky lg:top-28">
                            <div class="inline-flex items-center gap-2 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 shadow-[4px_4px_0_var(--sf-shadow)]">
                                <span class="font-display text-xl font-black leading-none text-[var(--sf-ink)]">{{ $step['number'] }}</span>
                                <span class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--sf-ink)]">{{ $step['kicker'] }}</span>
                            </div>
                            <h3 class="mt-5 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] text-balance sm:text-4xl">{{ $step['title'] }}</h3>
                            <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">{{ $step['body'] }}</p>

                            <ul class="mt-6 space-y-3">
                                @foreach ($step['points'] as $point)
                                    <li class="flex gap-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">
                                        <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-green)] text-[10px] font-black text-[var(--sf-ink)] shadow-[2px_2px_0_var(--sf-shadow)]">{{ $loop->iteration }}</span>
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <p class="neo-panel-tight mt-6 bg-[var(--sf-surface-warm)] p-4 text-sm font-bold leading-relaxed text-[var(--sf-ink)]">
                                <span class="font-black uppercase text-[var(--sf-accent)]">Wajib ingat:</span> {{ $step['watch'] }}
                            </p>
                        </div>

                        <figure class="neo-panel bg-[var(--sf-surface)] p-3">
                            <img src="{{ $guideImages[$step['image']] }}" alt="{{ $step['alt'] }}" class="w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-contain" loading="lazy">
                            <figcaption class="mt-3 rounded-lg border-2 border-dashed border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-xs font-black leading-relaxed text-[var(--sf-ink)]">
                                {{ $step['caption'] }}
                            </figcaption>
                        </figure>
                    </article>
                @endforeach
            </section>

            <section id="bantuan" class="neo-panel mt-20 grid scroll-mt-28 gap-8 bg-[var(--sf-surface)] p-6 md:grid-cols-[0.9fr_1.1fr] md:p-8">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-[var(--sf-accent)]">Troubleshooting</p>
                    <h2 class="mt-3 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Kalau gagal, jangan ulang asal-asalan.</h2>
                    <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Cek penyebab paling sering di bawah ini sebelum mencoba lagi. Kalau tetap buntu, hubungi support dan kirim invoice plus screenshot kendala tanpa membagikan password.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($troubleshooting as $item)
                        <article class="neo-panel-tight bg-[#fff8dc] p-5">
                            <p class="font-display text-sm font-black text-[var(--sf-ink)]">{{ $item['title'] }}</p>
                            <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $item['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section id="checklist" class="neo-panel mt-10 grid scroll-mt-28 gap-8 bg-[var(--sf-accent)] p-6 text-white md:grid-cols-[1fr_0.85fr] md:p-8">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-white/80">Checklist keamanan</p>
                    <h2 class="mt-3 font-display text-2xl font-black tracking-tight sm:text-4xl">Akun aman kalau empat poin ini sudah beres.</h2>
                    <p class="mt-4 max-w-xl text-sm font-semibold leading-relaxed text-white/85 sm:text-base">Selesaikan sebelum keluar dari halaman ini. Ini bagian yang paling sering dilewatkan setelah login berhasil.</p>
                    <ul class="mt-7 space-y-3">
                        @foreach ($securityChecklist as $item)
                            <li class="flex gap-3 text-sm font-semibold leading-relaxed text-white">
                                <span class="mt-1 h-3 w-3 shrink-0 rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)]"></span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ $supportDestination }}" data-telegram-link data-icon="telegram" class="neo-button mt-7 inline-flex h-12 items-center justify-center gap-2 bg-[var(--sf-yellow)] px-5 text-sm font-black text-[var(--sf-ink)]">@include('storefront.components.telegram-icon', ['class' => 'h-4 w-4 shrink-0']) <span>Butuh bantuan support</span></a>
                </div>
                <figure class="rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[6px_6px_0_var(--sf-shadow)]">
                    <img src="{{ $guideImages['security'] }}" alt="Menu Security and login untuk mengganti password dan mengatur ulang MFA" class="w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-contain" loading="lazy">
                    <figcaption class="mt-3 text-xs font-black leading-relaxed text-[var(--sf-ink)]">Ganti password terlebih dahulu, lalu pindahkan MFA ke perangkat kamu sendiri.</figcaption>
                </figure>
            </section>
        </main>
    </div>
</body>
</html>
