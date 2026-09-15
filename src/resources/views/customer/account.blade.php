<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">
            @include('storefront.partials.header')
        </div>

        <main class="flex-1 px-5 pb-20 pt-12 sm:pt-16">
            <div class="mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-6 shadow-[0_12px_30px_rgb(18_35_63_/_0.1)] sm:p-8">
                <p class="text-xs font-black uppercase tracking-[.18em] text-[var(--sf-accent)]">Akun pelanggan</p>
                <h1 class="mt-3 font-display text-4xl font-black tracking-tight">Daftar atau masuk tanpa password.</h1>
                <p class="mt-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Masukkan email. Kami kirim link sekali pakai yang berlaku 15 menit. Belanja tetap bisa dilakukan tanpa akun.</p>

                @if(session('customer_login_sent'))
                    <div class="mt-5 rounded-lg border border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4 text-sm font-bold">{{ session('customer_login_sent') }}</div>
                @endif
                @if(session('customer_login_error'))
                    <div class="mt-5 rounded-lg border border-[var(--sf-border)] bg-[#ffe8f0] p-4 text-sm font-bold">{{ session('customer_login_error') }}</div>
                @endif

                <div id="daftar" class="mt-6 rounded-xl border border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4">
                    <p class="text-xs font-black uppercase tracking-[.16em] text-[var(--sf-accent)]">Daftar / Masuk</p>
                    <p class="mt-1 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Akun pelanggan dipakai untuk melihat riwayat pesanan dan mengelola akses Anda.</p>
                </div>

                <form id="masuk" method="POST" action="{{ route('customer.login-link.request') }}" class="mt-6 space-y-4">
                    @csrf
                    <label class="block text-sm font-black">Email
                        <input name="email" type="email" required autocomplete="email" value="{{ old('email') }}" class="mt-2 w-full rounded-lg border border-[var(--sf-border)] bg-white px-4 py-3 font-bold focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">
                    </label>
                    @error('email')
                        <p class="text-sm font-bold text-[var(--sf-accent)]">{{ $message }}</p>
                    @enderror
                    <button class="neo-button w-full bg-[var(--sf-accent)] px-5 py-3 text-sm font-black text-white">Kirim link daftar / masuk</button>
                </form>

                <a href="{{ route('storefront.order-access.create') }}" class="mt-6 block text-center text-sm font-black underline underline-offset-4">Akses dengan invoice + Recovery Key</a>
            </div>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
