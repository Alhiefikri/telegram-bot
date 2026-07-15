<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head')
</head>
<body class="overflow-x-hidden antialiased selection:bg-[var(--sf-ink)] selection:text-white relative text-[var(--sf-ink)]">
    <div class="storefront-shell relative min-h-screen z-10 flex flex-col justify-between overflow-x-hidden">
        @include('storefront.partials.header')

        <main class="flex-grow px-5 py-14 sm:px-6 sm:py-20">
            <section class="neo-panel mx-auto max-w-xl bg-[var(--sf-surface)] p-6 sm:p-8" aria-labelledby="order-access-title">
                <p class="text-sm font-black text-[var(--sf-accent)]">Akses pesanan</p>
                <h1 id="order-access-title" class="mt-3 font-display text-3xl font-black leading-tight text-[var(--sf-ink)] sm:text-4xl">Buka kembali data akun.</h1>
                <p class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">Halaman ini hanya untuk pesanan yang dibeli melalui website. Masukkan nomor invoice dan Recovery Key yang muncul setelah checkout website; pesanan via Telegram tidak menggunakan Recovery Key. Kami tidak menyimpan key dalam bentuk yang bisa dibaca.</p>

                @if (session('storefront_order_access_error'))
                    <x-storefront.notice tone="danger" class="mt-5">{{ session('storefront_order_access_error') }}</x-storefront.notice>
                @endif

                <form method="POST" action="{{ route('storefront.order-access.store') }}" class="mt-8 grid gap-5">
                    @csrf
                    <div class="grid gap-2">
                        <label for="invoice-number" class="text-sm font-black text-[var(--sf-ink)]">Nomor invoice</label>
                        <input id="invoice-number" name="invoice_number" value="{{ old('invoice_number') }}" autocomplete="off" required class="neo-input h-12 w-full px-3 text-sm font-bold text-[var(--sf-ink)]" placeholder="INV-12345678">
                        @error('invoice_number')<p class="text-sm font-bold text-[#b42336]">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-2">
                        <label for="access-key" class="text-sm font-black text-[var(--sf-ink)]">Recovery key</label>
                        <input id="access-key" name="access_key" type="password" autocomplete="off" required class="neo-input h-12 w-full px-3 font-mono text-sm font-bold text-[var(--sf-ink)]" placeholder="LHIE-XXXXXXXXXXXXXXXXXXXX">
                        <p class="text-xs font-semibold leading-5 text-[var(--sf-muted)]">Gunakan key persis seperti yang Anda simpan. Jika hilang, data akun tidak bisa dibuka otomatis.</p>
                        @error('access_key')<p class="text-sm font-bold text-[#b42336]">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="neo-button inline-flex h-12 items-center justify-center bg-[var(--sf-accent)] px-5 text-sm font-black text-white">Buka data pesanan</button>
                </form>
            </section>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
