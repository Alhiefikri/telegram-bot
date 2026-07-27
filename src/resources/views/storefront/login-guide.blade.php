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
                    <a href="{{ route('storefront.guides.index') }}" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Semua Panduan</a>
                    <a href="#alur" class="rounded-md bg-[var(--sf-surface)] px-4 py-2 shadow-[3px_3px_0_var(--sf-shadow)]">Alur</a>
                    <a href="#langkah" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Panduan</a>
                    <a href="#bantuan" class="rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">Bantuan</a>
                    <a href="{{ $invoiceReturnUrl ?: route('storefront.order-access.create') }}" class="inline-flex items-center gap-1.5 rounded-md px-4 py-2 transition-colors hover:bg-[var(--sf-surface)]">{{ $invoiceReturnUrl ? 'Kembali ke Invoice' : 'Akses Pesanan' }}</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-5 py-12 sm:px-6 lg:py-18">
            <section class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                <div>
                    <x-storefront.badge class="px-4 py-2 text-xs tracking-[0.18em]">Panduan setelah pembelian</x-storefront.badge>
                    <h1 class="mt-5 max-w-3xl font-display text-4xl font-black leading-[0.98] tracking-tight text-[var(--sf-ink)] text-balance sm:text-6xl lg:text-7xl">
                        Cara login ChatGPT pakai Outlook/iCloud + MFA.
                    </h1>
                    <p class="mt-5 max-w-2xl text-base font-semibold leading-relaxed text-[var(--sf-muted)]">
                        Versi ini memakai gambar asli yang diekstrak dari PDF panduan, bukan screenshot penuh halaman PDF. Ikuti urutan email → password → Google Authenticator → kode MFA → pengamanan akun.
                    </p>

                    <div class="mt-8 grid gap-3 sm:grid-cols-3">
                        <x-storefront.button href="#alur">
                            Mulai dari alur
                        </x-storefront.button>
                        <x-storefront.button href="#langkah" variant="secondary">
                            Lihat langkah
                        </x-storefront.button>
                        <x-storefront.button :href="$invoiceReturnUrl ?: route('storefront.order-access.create')" variant="yellow">
                            {{ $invoiceReturnUrl ? 'Kembali ke invoice' : 'Akses pesanan' }}
                        </x-storefront.button>
                    </div>
                    @if ($invoiceReturnUrl)
                        <p class="mt-4 text-sm font-black leading-6 text-[var(--sf-muted)]">
                            Konteks invoice <span class="break-all font-mono text-[var(--sf-ink)]">{{ $invoiceNumber }}</span> tetap tersimpan di halaman ini.
                        </p>
                    @endif
                </div>

                <figure class="neo-panel relative overflow-hidden bg-[#0b1220] p-3 text-white">
                    <div class="absolute right-4 top-4 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">Visual guide</div>
                    <div class="grid gap-3 pt-10 sm:grid-cols-2">
                        <img src="{{ $guide['images']['email'] }}" alt="Contoh kolom email ChatGPT" class="aspect-[4/3] h-full w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-cover object-bottom shadow-[5px_5px_0_var(--sf-shadow)]" loading="eager">
                        <img src="{{ $guide['images']['password'] }}" alt="Contoh halaman password ChatGPT" class="aspect-[4/3] h-full w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-cover object-top shadow-[5px_5px_0_var(--sf-shadow)]" loading="eager">
                        <img src="{{ $guide['images']['authenticatorCode'] }}" alt="Contoh kode 6 digit Google Authenticator" class="max-h-52 w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-contain shadow-[5px_5px_0_var(--sf-shadow)] sm:col-span-2" loading="eager">
                    </div>
                    <figcaption class="mt-4 border-t-2 border-dashed border-white/30 pt-3 text-xs font-bold leading-relaxed text-white/80">
                        Gambar dipotong dari screenshot panduan agar fokus ke bagian yang harus diklik/diisi.
                    </figcaption>
                </figure>
            </section>

            <x-storefront.panel as="section" id="alur" class="mt-14 scroll-mt-28">
                <div class="flex flex-wrap items-start justify-between gap-5">
                    <div class="max-w-2xl">
                        <p class="text-xs font-black uppercase tracking-[0.24em] text-[var(--sf-accent)]">Alur cepat</p>
                        <h2 class="mt-3 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Jangan loncat. Login MFA harus berurutan.</h2>
                        <p class="mt-3 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Kalau satu tahap dilewati, ChatGPT biasanya menolak kode atau mengembalikan kamu ke halaman login.</p>
                    </div>
                    <x-storefront.button href="#checklist" variant="green" size="sm" class="h-11 text-xs">Checklist akhir</x-storefront.button>
                </div>

                <ol class="mt-6 grid gap-3 md:grid-cols-5">
                    @foreach ($guide['quick_flow'] as $flow)
                        <li class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-3 shadow-[3px_3px_0_var(--sf-shadow)]">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] text-xs font-black text-[var(--sf-ink)]">{{ $loop->iteration }}</span>
                            <p class="mt-3 text-sm font-black leading-snug text-[var(--sf-ink)]">{{ $flow }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-storefront.panel>

            <section class="mt-10 grid gap-4 md:grid-cols-4" aria-label="Siapkan data login dulu">
                <div class="md:col-span-4">
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-[var(--sf-accent)]">Data login yang harus disiapkan</p>
                    <p class="mt-2 max-w-2xl text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">Empat data ini harus sudah siap sebelum membuka halaman login agar proses email, password, dan MFA tidak bolak-balik.</p>
                </div>
                @foreach ($guide['prep_items'] as $item)
                    <x-storefront.panel variant="tight" padding="sm">
                        <p class="font-display text-sm font-black text-[var(--sf-ink)]">{{ $item['label'] }}</p>
                        <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $item['body'] }}</p>
                    </x-storefront.panel>
                @endforeach
            </section>

            <section id="langkah" class="mt-20 space-y-14 scroll-mt-28">
                <div class="max-w-2xl">
                    <x-storefront.badge class="px-4 py-2 text-xs tracking-[0.18em]">Langkah lengkap</x-storefront.badge>
                    <h2 class="mt-5 font-display text-3xl font-black tracking-tight text-[var(--sf-ink)] sm:text-5xl">Ikuti bagian teks, lalu cocokkan dengan gambar.</h2>
                    <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Setiap screenshot hanya menampilkan bagian penting: kolom yang diisi, tombol yang ditekan, dan area keamanan yang harus dicek setelah login berhasil.</p>
                </div>

                @foreach ($guide['steps'] as $index => $step)
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

                            <x-storefront.panel variant="tight" tone="warm" padding="sm" class="mt-6 text-sm font-bold leading-relaxed">
                                <span class="font-black uppercase text-[var(--sf-accent)]">Wajib ingat:</span> {{ $step['watch'] }}
                            </x-storefront.panel>
                        </div>

                        <figure class="neo-panel bg-[var(--sf-surface)] p-3">
                            <img src="{{ $guide['images'][$step['image']] }}" alt="{{ $step['alt'] }}" class="w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-contain" loading="lazy">
                            <figcaption class="mt-3 rounded-lg border-2 border-dashed border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-xs font-black leading-relaxed text-[var(--sf-ink)]">
                                {{ $step['caption'] }}
                            </figcaption>
                        </figure>
                    </article>
                @endforeach
            </section>

            <x-storefront.panel as="section" id="bantuan" class="mt-20 grid scroll-mt-28 gap-8 md:grid-cols-[0.9fr_1.1fr]" padding="lg">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-[var(--sf-accent)]">Troubleshooting</p>
                    <h2 class="mt-3 font-display text-2xl font-black tracking-tight text-[var(--sf-ink)] sm:text-4xl">Kalau gagal, jangan ulang asal-asalan.</h2>
                    <p class="mt-4 text-sm font-semibold leading-relaxed text-[var(--sf-muted)] sm:text-base">Cek penyebab paling sering di bawah ini sebelum mencoba lagi. Simpan invoice dan screenshot kendala pribadi, tetapi jangan pernah membagikan password atau recovery key.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($guide['troubleshooting'] as $item)
                        <article class="neo-panel-tight bg-[#fff8dc] p-5">
                            <p class="font-display text-sm font-black text-[var(--sf-ink)]">{{ $item['title'] }}</p>
                            <p class="mt-2 text-sm font-semibold leading-relaxed text-[var(--sf-muted)]">{{ $item['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </x-storefront.panel>

            <x-storefront.panel as="section" id="checklist" tone="accent" class="mt-10 grid scroll-mt-28 gap-8 md:grid-cols-[1fr_0.85fr]" padding="lg">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.24em] text-white/80">Checklist keamanan</p>
                    <h2 class="mt-3 font-display text-2xl font-black tracking-tight sm:text-4xl">Akun aman kalau empat poin ini sudah beres.</h2>
                    <p class="mt-4 max-w-xl text-sm font-semibold leading-relaxed text-white/85 sm:text-base">Selesaikan sebelum keluar dari halaman ini. Ini bagian yang paling sering dilewatkan setelah login berhasil.</p>
                    <ul class="mt-7 space-y-3">
                        @foreach ($guide['security_checklist'] as $item)
                            <li class="flex gap-3 text-sm font-semibold leading-relaxed text-white">
                                <span class="mt-1 h-3 w-3 shrink-0 rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)]"></span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <x-storefront.button :href="$invoiceReturnUrl ?: route('storefront.order-access.create')" variant="yellow" class="mt-7">{{ $invoiceReturnUrl ? 'Kembali ke invoice' : 'Buka akses pesanan' }}</x-storefront.button>
                </div>
                <figure class="rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-3 shadow-[6px_6px_0_var(--sf-shadow)]">
                    <img src="{{ $guide['images']['security'] }}" alt="Menu Security and login untuk mengganti password dan mengatur ulang MFA" class="w-full rounded-lg border-[3px] border-[var(--sf-border)] bg-white object-contain" loading="lazy">
                    <figcaption class="mt-3 text-xs font-black leading-relaxed text-[var(--sf-ink)]">Ganti password terlebih dahulu, lalu pindahkan MFA ke perangkat kamu sendiri.</figcaption>
                </figure>
            </x-storefront.panel>
        </main>
    </div>
</body>
</html>
