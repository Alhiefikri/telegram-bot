<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <title>@yield('title') · Admin LHIE STORE</title>
        @include('partials.theme-init')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="admin-page admin-dashboard-page min-h-full font-sans antialiased">
        <main class="admin-mini-shell flex min-h-screen flex-col gap-4 px-3 pb-12 pt-3 sm:px-4 md:pt-6">
            <x-admin-dashboard.card
                as="header"
                padding="sm"
                shadow="sm"
                role="banner"
                aria-label="Navigasi supplier"
                class="sticky top-3 z-30 flex flex-wrap items-center justify-between gap-3 rounded-[1.125rem]"
            >
                <div class="min-w-0">
                    <p class="font-display text-[10px] font-black uppercase tracking-[0.18em] text-brand-muted">LHIE STORE ADMIN · SUPPLIER</p>
                    <h1 class="mt-0.5 truncate font-display text-lg font-black uppercase tracking-wide text-neo-ink">@yield('title')</h1>
                </div>
                <x-theme-toggle class="h-10 w-10 shrink-0 rounded-lg border-2 border-neo-ink bg-white text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
            </x-admin-dashboard.card>

            @include('admin.suppliers.partials.nav', ['supplier' => $supplier ?? null])

            @if (! ($passwordConfirmedRecently ?? true))
                <x-admin-dashboard.notice tone="warning" role="alert" class="animate-slide-up rounded-xl">
                    Sesi konfirmasi password sudah berakhir.
                    <a class="underline" href="{{ route('password.confirm') }}">Konfirmasi password dulu</a>
                    sebelum menyimpan perubahan.
                </x-admin-dashboard.notice>
            @endif

            @if (session('status'))
                <x-admin-dashboard.notice tone="success" role="status" aria-live="polite" class="animate-slide-up rounded-xl">{{ session('status') }}</x-admin-dashboard.notice>
            @endif

            @php($membershipErrors = $errors->getBag('productFamilyMembership'))
            @if ($errors->any() || $membershipErrors->any())
                <x-admin-dashboard.notice tone="danger" role="alert" class="animate-slide-up rounded-xl">
                    <p class="font-black uppercase tracking-wide">Periksa kembali isian berikut</p>
                    <ul class="mt-1 list-disc space-y-1 pl-5 text-sm font-bold">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                        @foreach ($membershipErrors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-admin-dashboard.notice>
            @endif

            @yield('content')
        </main>
    </body>
</html>
