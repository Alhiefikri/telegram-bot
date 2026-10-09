<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <title>Login Admin LHIE STORE</title>
        @include('partials.theme-init')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="admin-page admin-login-page flex min-h-full items-center justify-center px-4 py-10 pt-20 text-admin-ink antialiased">
        <x-theme-toggle showLabel class="fixed right-4 top-4 z-30 rounded-lg border border-admin-line bg-admin-surface px-3 text-xs font-medium text-admin-ink shadow-admin-sm transition-colors hover:bg-admin-surface-muted focus:outline-none focus:ring-2 focus:ring-admin-focus" />
        <main class="w-full max-w-md">
            <x-admin-dashboard.card as="section" padding="compact" shadow="md" class="p-6">
                <div class="mb-6 rounded-lg border border-admin-line bg-admin-accent-soft p-4">
                    <p class="text-xs font-medium text-admin-muted">Admin workspace</p>
                    <h1 class="mt-2 text-3xl font-semibold leading-tight text-admin-ink">LHIE STORE</h1>
                    <p class="mt-2 text-sm text-admin-muted">Masuk untuk mengelola toko.</p>
                </div>

                @if ($errors->any())
                    <x-admin-dashboard.notice tone="danger" class="mb-4">
                        {{ $errors->first() }}
                    </x-admin-dashboard.notice>
                @endif

                <form method="POST" action="{{ route('admin.login.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <x-admin-dashboard.field-label for="email">Username / email admin</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input
                            id="email"
                            name="email"
                            type="text"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            autofocus
                            required
                            class="mt-2"
                            placeholder="username"
                        />
                    </div>

                    <div x-data="passwordReveal()">
                        <x-admin-dashboard.field-label for="password">Password</x-admin-dashboard.field-label>
                        <div class="relative mt-2">
                            <x-admin-dashboard.input
                                id="password"
                                name="password"
                                type="password"
                                x-ref="password"
                                autocomplete="current-password"
                                required
                                class="pr-32"
                                placeholder="••••••••"
                            />
                            <x-admin-dashboard.button
                                type="button"
                                variant="warning"
                                size="sm"
                                class="absolute bottom-2 right-2 top-2 min-h-0 min-w-24 px-3 py-0 text-[11px]"
                                aria-controls="password"
                                x-bind:aria-label="buttonLabel"
                                x-bind:aria-pressed="showPassword.toString()"
                                x-on:click="toggle()"
                            >
                                <span x-text="buttonText">Lihat</span>
                            </x-admin-dashboard.button>
                        </div>
                    </div>

                    <x-admin-dashboard.checkbox name="remember" :checked="old('remember')">
                        Ingat sesi admin
                    </x-admin-dashboard.checkbox>

                    <x-admin-dashboard.button type="submit" variant="primary" class="w-full">
                        Masuk Dashboard
                    </x-admin-dashboard.button>
                </form>
            </x-admin-dashboard.card>
        </main>
    </body>
</html>
