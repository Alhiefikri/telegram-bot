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
        <style>
            body {
                min-height: 100vh;
                background-color: #fff6cf;
                background-image:
                    radial-gradient(circle at top left, rgba(123, 209, 10, 0.26), transparent 26%),
                    radial-gradient(circle at top right, rgba(219, 63, 152, 0.18), transparent 22%),
                    repeating-linear-gradient(135deg, transparent, transparent 22px, rgba(5,5,5,0.04) 22px, rgba(5,5,5,0.04) 24px);
                font-family: 'Plus Jakarta Sans', sans-serif;
            }
        </style>
    </head>
    <body class="flex min-h-full items-center justify-center px-4 py-10 text-neo-ink antialiased">
        <main class="w-full max-w-md">
            <section class="rounded-[2rem] border-[4px] border-neo-ink bg-white p-6 shadow-[8px_8px_0_0_#050505]">
                <div class="mb-6 rounded-[1.25rem] border-[3px] border-neo-ink bg-[#f2da3d] p-4 shadow-[4px_4px_0_0_#050505]">
                    <p class="font-display text-[11px] font-black uppercase tracking-[0.24em] text-neo-ink/70">Blade-only Admin /// Neobrutal</p>
                    <h1 class="mt-2 font-display text-3xl font-black uppercase leading-none text-neo-ink">LHIE STORE</h1>
                    <p class="mt-2 text-sm font-bold text-neo-ink/70">Masuk ke dashboard neobrutalisme. CRUD tetap di halaman ini.</p>
                </div>

                @if ($errors->any())
                    <div class="mb-4 rounded-[1rem] border-[3px] border-neo-ink bg-[#fecaca] p-3 text-sm font-bold text-[#991b1b] shadow-[3px_3px_0_0_#050505]">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.login.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="font-display text-xs font-black uppercase tracking-wider text-neo-ink">Username / email admin</label>
                        <input
                            id="email"
                            name="email"
                            type="text"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            autofocus
                            class="mt-2 w-full rounded-[1rem] border-[3px] border-neo-ink bg-white px-4 py-3 text-sm font-bold text-neo-ink outline-none transition focus:shadow-[0_0_0_4px_rgba(135,88,222,0.18)]"
                            placeholder="username"
                        >
                    </div>

                    <div>
                        <label for="password" class="font-display text-xs font-black uppercase tracking-wider text-neo-ink">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            class="mt-2 w-full rounded-[1rem] border-[3px] border-neo-ink bg-white px-4 py-3 text-sm font-bold text-neo-ink outline-none transition focus:shadow-[0_0_0_4px_rgba(135,88,222,0.18)]"
                            placeholder="••••••••"
                        >
                    </div>

                    <label class="flex items-center gap-3 rounded-[1rem] border-[3px] border-neo-ink bg-[#ede5fa] px-4 py-3 text-sm font-bold text-neo-ink shadow-[3px_3px_0_0_#050505]">
                        <input type="checkbox" name="remember" value="1" class="h-5 w-5 rounded-none border-neo-ink text-[#8758de]">
                        Ingat sesi admin
                    </label>

                    <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-[1rem] border-[3px] border-neo-ink bg-[#7BD10A] px-5 py-3 font-display text-sm font-black uppercase tracking-wide text-neo-ink shadow-[5px_5px_0_0_#050505] transition hover:-translate-y-0.5 active:translate-x-[1px] active:translate-y-[1px] active:shadow-[1px_1px_0_0_#050505]">
                        Masuk Dashboard
                    </button>
                </form>
            </section>
        </main>
    </body>
</html>
