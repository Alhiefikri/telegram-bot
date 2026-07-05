<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login Admin LHIE STORE</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="admin-page admin-login-page flex min-h-full items-center justify-center px-4 py-10 text-neo-ink antialiased">
        <main class="w-full max-w-md">
            <x-admin-dashboard.card as="section" padding="compact" shadow="md" class="rounded-neo-5xl border-4 bg-white p-6 shadow-neo-3xl">
                <div class="mb-6 rounded-neo-3xl border-[3px] border-neo-ink bg-neo-yellow p-4 shadow-neo-md">
                    <p class="font-display text-[11px] font-black uppercase tracking-[0.24em] text-neo-ink/70">Blade-only Admin /// Neobrutal</p>
                    <h1 class="mt-2 font-display text-3xl font-black uppercase leading-none text-neo-ink">LHIE STORE</h1>
                    <p class="mt-2 text-sm font-bold text-neo-ink/70">Masuk ke dashboard neobrutalisme. CRUD tetap di halaman ini.</p>
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
