@props([
    'title' => 'Admin Dashboard',
])

<div {{ $attributes->class(['admin-dashboard-shell min-h-screen bg-admin-paper text-admin-ink']) }}>
    <a href="#admin-dashboard-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-admin-surface focus:px-4 focus:py-3 focus:text-sm focus:font-semibold focus:text-admin-ink focus:shadow-admin-sm">
        Lewati ke konten utama
    </a>

    @if (isset($sidebar) && trim((string) $sidebar) !== '')
        <aside class="admin-dashboard-sidebar hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-60 lg:flex-col" aria-label="Navigasi admin">
            {{ $sidebar }}
        </aside>
    @endif

    <div class="flex min-h-screen min-w-0 flex-col lg:pl-60">
        @if (isset($topbar) && trim((string) $topbar) !== '')
            <header class="admin-dashboard-topbar sticky top-0 z-20 border-b border-admin-line bg-admin-paper/95 backdrop-blur" aria-label="Konteks halaman">
                {{ $topbar }}
            </header>
        @endif

        @if (isset($mobileDrawer) && trim((string) $mobileDrawer) !== '')
            {{ $mobileDrawer }}
        @endif

        <main id="admin-dashboard-content" tabindex="-1" class="min-w-0 flex-1 px-4 py-5 sm:px-6 lg:px-8">
            @if (isset($status) && trim((string) $status) !== '')
                <div class="mb-5">{{ $status }}</div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>
