<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="bg-[var(--sf-paper)]">
<head>@include('storefront.partials.head', ['metadata' => $metadata])</head>
<body class="customer-auth text-[var(--sf-ink)]">
    <div class="storefront-shell flex min-h-screen flex-col">
        <div class="pt-4">@include('storefront.partials.header')</div>
        <main class="flex-1 px-5 pb-20 pt-8 sm:pt-12">
            <div class="auth-panel mx-auto max-w-xl rounded-2xl border border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 sm:p-8">
                @include('customer.partials.navigation')
                <h1 class="font-display text-3xl font-black tracking-tight">Keamanan akun</h1>
                @include('customer.partials.auth-feedback')
                @if($customer->password === null)
                    <p class="mt-3 text-sm leading-6 text-[var(--sf-muted)]">Tambahkan password pertama dalam lima menit setelah masuk melalui link email. Pendaftaran ulang tidak dapat mengubah akun Anda.</p>
                    <form method="POST" action="{{ route('customer.password.enroll') }}" class="mt-6 space-y-4" data-auth-form>
                        @csrf
                        <label class="block text-sm font-black">Password baru
                            <input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="enroll-password-help" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <p id="enroll-password-help" class="text-sm text-[var(--sf-muted)]">12–128 karakter. Spasi dan pengelola password dapat digunakan.</p>
                        <label class="block text-sm font-black">Ulangi password baru
                            <input name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" class="mt-2 w-full rounded-lg border border-[var(--sf-muted)] bg-white px-4 py-3 focus:ring-2 focus:ring-[var(--sf-accent)]">
                        </label>
                        <button class="neo-button w-full bg-[var(--sf-accent)] px-5 py-3 text-sm font-black text-white">Simpan password pertama</button>
                        <p data-auth-progress role="status" class="text-sm font-semibold" hidden>Mengirim permintaan...</p>
                    </form>
                @else
                    <p class="mt-4 text-sm leading-6">Password akun sudah tersedia. Untuk menggantinya, keluar lalu gunakan instruksi pemulihan password melalui email.</p>
                @endif
                <a href="{{ route('customer.orders.index') }}" class="mt-5 block py-3 text-center text-sm font-black underline underline-offset-4">Kembali ke pesanan</a>
                <form method="POST" action="{{ route('customer.logout') }}" class="mt-4">
                    @csrf
                    <button class="neo-button w-full bg-[var(--sf-ink)] px-5 py-3 text-sm font-black text-white">Keluar dari akun pelanggan</button>
                </form>
            </div>
        </main>
        @include('storefront.partials.footer')
    </div>
    @include('customer.partials.auth-progress')
</body>
</html>
