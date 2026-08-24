<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Konfirmasi Password Owner</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="admin-page flex min-h-full items-center justify-center px-4 py-10 text-neo-ink antialiased">
        <main class="w-full max-w-md">
            <x-admin-dashboard.card as="section" padding="compact" shadow="md" class="rounded-neo-5xl border-4 bg-white p-6 shadow-neo-3xl">
                <p class="font-display text-[11px] font-black uppercase tracking-[0.2em] text-brand-muted">Sensitive Owner action</p>
                <h1 class="mt-2 font-display text-2xl font-black uppercase">Konfirmasi password</h1>
                <p class="mt-3 text-sm font-semibold leading-6 text-brand-muted">Masukkan password admin lagi untuk melanjutkan perubahan BSC Receiving Address.</p>

                @if ($errors->any())
                    <x-admin-dashboard.notice tone="danger" class="mt-4">
                        {{ $errors->first() }}
                    </x-admin-dashboard.notice>
                @endif

                <form method="POST" action="{{ route('admin.password.confirm.store') }}" class="mt-5 space-y-4">
                    @csrf
                    <x-admin-dashboard.field-label for="password">Password</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="password" name="password" type="password" autocomplete="current-password" required autofocus />
                    <x-admin-dashboard.button type="submit" variant="primary" class="w-full">Konfirmasi</x-admin-dashboard.button>
                </form>
            </x-admin-dashboard.card>
        </main>
    </body>
</html>
