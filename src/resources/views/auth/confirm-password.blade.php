<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>Konfirmasi Password Owner</title>
        @include('partials.theme-init')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="admin-page flex min-h-full items-center justify-center px-4 py-10 pt-20 text-admin-ink antialiased">
        <x-theme-toggle showLabel class="fixed right-4 top-4 z-30 h-11 rounded-lg border border-admin-line bg-admin-surface px-3 text-sm font-medium text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus" />
        <main class="w-full max-w-md">
            <x-admin-dashboard.card as="section" padding="compact" shadow="md">
                <p class="text-xs font-medium text-admin-muted">Sensitive Owner action</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-admin-ink">Konfirmasi password</h1>
                <p class="mt-3 text-sm leading-6 text-admin-muted">Masukkan password admin lagi untuk melanjutkan tindakan ini.</p>

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
