<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="text-[var(--sf-ink)]">
<div class="storefront-shell flex min-h-screen flex-col">
    <div class="pt-4">@include('storefront.partials.header')</div>
    <main class="mx-auto w-full max-w-4xl flex-1 px-5 pb-24 pt-12">
        @include('customer.partials.navigation')
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-black uppercase tracking-[.18em] text-[var(--sf-accent)]">Akun pelanggan</p>
                <h1 class="mt-2 font-display text-4xl font-black">Saldo saya</h1>
                <p class="mt-2 text-sm font-bold text-[var(--sf-muted)]">{{ $customer->email }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('customer.logout') }}">
                    @csrf
                    <button class="rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 py-3 text-sm font-black">Keluar</button>
                </form>
            </div>
        </div>

        <section class="mt-8 grid gap-4 sm:grid-cols-2" aria-label="Ringkasan saldo">
            <article class="rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[5px_5px_0_var(--sf-shadow)]">
                <p class="text-xs font-black uppercase tracking-[.16em] text-[var(--sf-muted)]">Store Balance · IDR</p>
                @if ($overview->storeBalance->isAvailable())
                    <p class="mt-3 break-words font-display text-3xl font-black">{{ $idrBalanceDisplay }}</p>
                    <p class="mt-2 text-xs font-bold text-[var(--sf-muted)]">Saldo tersedia berdasarkan ledger.</p>
                @else
                    <p class="mt-3 text-sm font-black text-rose-700">Saldo sementara tidak tersedia.</p>
                    <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">Data saldo sedang diperiksa; nominal tidak ditampilkan.</p>
                @endif
            </article>

            <article class="rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[5px_5px_0_var(--sf-shadow)]">
                <p class="text-xs font-black uppercase tracking-[.16em] text-[var(--sf-muted)]">Customer Balance · USDT</p>
                @if ($overview->usdtBalance->isAvailable())
                    <p class="mt-3 break-words font-display text-3xl font-black">{{ $usdtBalanceDisplay }}</p>
                    @if ($overview->usdtBalance->isFrozen())
                        <p class="mt-2 text-xs font-black text-amber-800">Saldo dibekukan sementara untuk pemeriksaan. Nominal tetap ditampilkan sebagai proyeksi tercatat.</p>
                    @else
                        <p class="mt-2 text-xs font-bold text-[var(--sf-muted)]">Saldo tersedia berdasarkan ledger.</p>
                    @endif
                @else
                    <p class="mt-3 text-sm font-black text-rose-700">Saldo sementara tidak tersedia.</p>
                    <p class="mt-1 text-xs font-bold text-[var(--sf-muted)]">Data saldo sedang diperiksa; nominal tidak ditampilkan.</p>
                @endif
            </article>
        </section>

        <p class="mt-5 rounded-lg border-2 border-[var(--sf-border)] bg-white p-3 text-xs font-bold leading-5 text-[var(--sf-muted)]">
            Halaman ini hanya-baca. Top-up, pembayaran, dan perubahan saldo tidak tersedia melalui web.
        </p>

        <section class="mt-8" aria-labelledby="balance-history-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-black uppercase tracking-[.16em] text-[var(--sf-muted)]">Riwayat</p>
                    <h2 id="balance-history-heading" class="mt-1 font-display text-2xl font-black">Aktivitas saldo</h2>
                </div>
                <nav class="flex gap-2" aria-label="Pilih mata uang saldo">
                    @foreach (['IDR', 'USDT'] as $currencyTab)
                        <a href="{{ route('customer.balance.show', ['currency' => $currencyTab]) }}"
                           aria-current="{{ $currency === $currencyTab ? 'page' : 'false' }}"
                           class="rounded-full border-[3px] border-[var(--sf-border)] px-4 py-2 text-sm font-black shadow-[3px_3px_0_var(--sf-shadow)] {{ $currency === $currencyTab ? 'bg-[var(--sf-accent)] text-white' : 'bg-white text-[var(--sf-ink)]' }}">
                            {{ $currencyTab }}
                        </a>
                    @endforeach
                </nav>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($activityRows as $row)
                    <article class="rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-4 shadow-[4px_4px_0_var(--sf-shadow)]">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-black">{{ $row['kind'] }}</h3>
                                <p class="mt-1 break-all text-xs font-bold text-[var(--sf-muted)]">{{ $row['reference'] }}</p>
                            </div>
                            <p class="max-w-full break-words text-right font-display text-lg font-black">{{ $row['amount'] }}</p>
                        </div>
                        <div class="mt-3 flex flex-wrap justify-between gap-2 text-xs font-bold text-[var(--sf-muted)]">
                            <span>{{ $row['state'] }}</span>
                            <time>{{ $row['occurred_at'] }}</time>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border-[3px] border-[var(--sf-border)] bg-white p-8 text-center font-bold">
                        Belum ada aktivitas {{ $currency }} pada akun ini.
                    </div>
                @endforelse
            </div>

            @if ($pageLinks['newer'] || $pageLinks['older'])
                <nav class="mt-6 flex flex-wrap justify-between gap-3" aria-label="Paginasi riwayat saldo">
                    @if ($pageLinks['newer'])
                        <a href="{{ $pageLinks['newer'] }}" class="neo-button bg-white px-4 py-3 text-sm font-black">Aktivitas lebih baru</a>
                    @else
                        <span></span>
                    @endif
                    @if ($pageLinks['older'])
                        <a href="{{ $pageLinks['older'] }}" class="neo-button bg-white px-4 py-3 text-sm font-black">Aktivitas lebih lama</a>
                    @endif
                </nav>
            @endif
        </section>
    </main>
    @include('storefront.partials.footer')
</div>
</body>
</html>
