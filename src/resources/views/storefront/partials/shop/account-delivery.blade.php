<x-storefront.panel as="section" class="mt-5" aria-label="Pengiriman data akun">
    @if (! $isPaid)
        @if ($isTerminal)
            <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Data akun tidak dikirim dari invoice ini</h2>
            <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                Invoice ini sudah tidak aktif. Buat order baru jika masih ingin membeli produk ini.
            </p>
        @else
            <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Data akun aman sampai pembayaran masuk</h2>
            <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
                Setelah pembayaran terverifikasi, data akun muncul di halaman ini. Simpan link invoice ini sampai akun diterima.
            </p>
        @endif
    @elseif ($accountGroups !== [] && $canRevealCredentials)
        <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Data akun</h2>
        <div class="mt-5 space-y-4">
            @foreach ($accountGroups as $group)
                <section class="rounded-lg border-[3px] border-[var(--sf-border)] bg-white p-4 shadow-[5px_5px_0_var(--sf-shadow)]" aria-label="Data akun {{ $group['product_name'] }}">
                    <h3 class="font-display text-lg font-black text-[var(--sf-ink)]">{{ $group['product_name'] }}</h3>
                    <div class="mt-3 space-y-3">
                        @foreach ($group['secrets'] as $secret)
                            <pre class="overflow-x-auto whitespace-pre-wrap rounded-md border-[3px] border-[var(--sf-border)] bg-[var(--sf-ink)] p-3 font-mono text-sm font-bold leading-6 text-white shadow-[3px_3px_0_var(--sf-shadow)]">{{ $secret }}</pre>
                        @endforeach
                    </div>
                    @if (filled($group['delivery_note']))
                        <div class="mt-4 rounded-md border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-sm font-bold leading-6 text-[var(--sf-ink)]">
                            <p class="font-display text-xs font-black uppercase tracking-[0.14em]">Catatan pengiriman</p>
                            <p class="mt-2 whitespace-pre-line">{{ $group['delivery_note'] }}</p>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
        <p class="mt-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-3 text-sm font-black leading-6 text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)]">
            Simpan data ini di password manager pribadi atau screenshot pribadi yang aman. Jangan bagikan ke orang lain atau menyimpannya di perangkat umum.
        </p>
    @elseif ($accountGroups !== [])
        <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Data akun siap dibuka</h2>
        <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">Untuk melindungi data akun, buka kembali menggunakan nomor invoice dan recovery key yang Anda simpan saat checkout.</p>
        <a href="{{ $orderAccessUrl }}" class="neo-button mt-5 inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Akses data pesanan</a>
    @else
        <h2 class="font-display text-2xl font-black text-[var(--sf-ink)]">Pembayaran diterima</h2>
        <p class="mt-3 text-sm font-bold leading-6 text-[var(--sf-muted)]">
            Pembayaran diterima. Produk ini tidak punya data akun otomatis; admin akan follow up memakai invoice ini.
        </p>
    @endif

    <a href="{{ route('storefront.login-guide') }}" class="mt-5 inline-flex w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-5 py-3 text-center font-display text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:w-auto">
        Panduan login &amp; keamanan
    </a>
    <a href="{{ route('customer.account') }}" class="mt-3 inline-flex w-full items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-5 py-3 text-center font-display text-sm font-black uppercase tracking-[0.18em] text-[var(--sf-ink)] shadow-[4px_4px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[6px_6px_0_var(--sf-shadow)] sm:ml-3 sm:w-auto">
        Masuk / klaim ke akun
    </a>
</x-storefront.panel>
