<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">@include('storefront.partials.header')</div>
        <main class="flex-1 px-5 pb-20 pt-8 sm:pt-12">
            <div class="mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 sm:p-8">
                <h1 class="font-display text-3xl font-black tracking-tight sm:text-4xl">Masuk ke akun pelanggan</h1>
                <p class="mt-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">Lihat pesanan dan saldo akun Anda. Belanja tanpa akun dan akses dengan Recovery Key tetap tersedia.</p>
                @include('customer.partials.auth-feedback')

                <form id="masuk" method="POST" action="{{ route('customer.login') }}" class="mt-6 space-y-4" data-auth-form>
                    @csrf
                    <label class="block text-sm font-black">Email
                        <input name="email" type="email" required maxlength="254" autocomplete="username" value="{{ old('email') }}" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 font-bold focus:ring-2 focus:ring-[var(--sf-accent)]">
                    </label>
                    <label class="block text-sm font-black">Password
                        <input name="password" type="password" required maxlength="128" autocomplete="current-password" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 font-bold focus:ring-2 focus:ring-[var(--sf-accent)]">
                    </label>
                    <button class="neo-button w-full bg-[var(--sf-accent)] px-5 py-3 text-sm font-black text-white">Masuk dengan password</button>
                    <p data-auth-progress role="status" class="text-sm font-semibold" hidden>Mengirim permintaan...</p>
                </form>

                <details id="daftar" class="mt-6 border-t border-[var(--sf-border)] pt-4" @if($errors->has('password_confirmation') || $errors->has('name')) open @endif>
                    <summary class="cursor-pointer py-3 text-sm font-black">Daftar akun baru</summary>
                    <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">Verifikasi email sebelum masuk. Akun yang sudah ada tidak diubah oleh pendaftaran ulang.</p>
                    <form method="POST" action="{{ route('customer.register') }}" class="mt-4 space-y-4" data-auth-form>
                        @csrf
                        <label class="block text-sm font-black">Email
                            <input name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <label class="block text-sm font-black">Nama (opsional)
                            <input name="name" type="text" maxlength="255" autocomplete="name" value="{{ old('name') }}" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <label class="block text-sm font-black">Password baru
                            <input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="register-password-help" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <p id="register-password-help" class="text-sm text-[var(--sf-muted)]">12–128 karakter. Spasi dan pengelola password dapat digunakan.</p>
                        <label class="block text-sm font-black">Ulangi password baru
                            <input name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <button class="neo-button w-full bg-[var(--sf-ink)] px-5 py-3 text-sm font-black text-white">Daftar dan kirim verifikasi</button>
                        <p data-auth-progress role="status" class="text-sm font-semibold" hidden>Mengirim permintaan...</p>
                    </form>
                </details>

                <details class="mt-4 border-t border-[var(--sf-border)] pt-4">
                    <summary class="cursor-pointer py-3 text-sm font-black">Lupa password?</summary>
                    <form method="POST" action="{{ route('customer.password-reset.request') }}" class="mt-4 space-y-4" data-auth-form>
                        @csrf
                        <label class="block text-sm font-black">Email akun
                            <input name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <button class="neo-button w-full bg-[var(--sf-ink)] px-5 py-3 text-sm font-black text-white">Kirim instruksi pemulihan</button>
                        <p data-auth-progress role="status" class="text-sm font-semibold" hidden>Mengirim permintaan...</p>
                    </form>
                </details>

                <details class="mt-4 border-t border-[var(--sf-border)] pt-4">
                    <summary class="cursor-pointer py-3 text-sm font-black">Masuk tanpa password melalui link email</summary>
                    <p class="mt-2 text-sm leading-6 text-[var(--sf-muted)]">Link sekali pakai berlaku 15 menit. Metode ini tetap tersedia untuk akun lama.</p>
                    <form method="POST" action="{{ route('customer.login-link.request') }}" class="mt-4 space-y-4" data-auth-form>
                        @csrf
                        <label class="block text-sm font-black">Email
                            <input name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <button class="neo-button w-full bg-[var(--sf-ink)] px-5 py-3 text-sm font-black text-white">Kirim link daftar / masuk</button>
                        <p data-auth-progress role="status" class="text-sm font-semibold" hidden>Mengirim permintaan...</p>
                    </form>
                </details>
                <a href="{{ route('storefront.order-access.create') }}" class="mt-6 block py-3 text-center text-sm font-black underline underline-offset-4">Akses dengan invoice + Recovery Key</a>
            </div>
        </main>
        @include('storefront.partials.footer')
    </div>
    @include('customer.partials.auth-progress')
</body>
</html>
