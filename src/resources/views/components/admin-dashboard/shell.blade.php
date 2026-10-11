@props([
    'title' => 'Admin Dashboard',
])

<div {{ $attributes->class(['admin-dashboard-shell min-h-screen bg-admin-paper text-admin-ink'])->merge(['x-bind:class' => "{ 'admin-sidebar-collapsed': sidebarCollapsed }"]) }}>
    <a href="#admin-dashboard-content" @if(isset($mobileDrawer)) x-bind:inert="sidebarOpen" @endif class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-admin-surface focus:px-4 focus:py-3 focus:text-sm focus:font-semibold focus:text-admin-ink focus:shadow-admin-sm">
        Lewati ke konten utama
    </a>

    @if (isset($sidebar) && trim((string) $sidebar) !== '')
        <aside id="admin-desktop-sidebar" class="admin-dashboard-sidebar hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-60 lg:flex-col" aria-label="Navigasi admin">
            {{ $sidebar }}
        </aside>
    @endif

    <div class="admin-dashboard-frame flex min-w-0 flex-col lg:pl-60" x-bind:class="{ 'admin-dashboard-frame-collapsed': sidebarCollapsed }">
        @if (isset($topbar) && trim((string) $topbar) !== '')
            <header x-ref="dashboardTopbar" class="admin-dashboard-topbar sticky top-0 z-20 border-b border-admin-line bg-admin-paper" aria-label="Konteks halaman" @if(isset($mobileDrawer)) x-bind:inert="sidebarOpen" @endif>
                {{ $topbar }}
            </header>
        @endif

        @if (isset($mobileDrawer) && trim((string) $mobileDrawer) !== '')
            {{ $mobileDrawer }}
        @endif

        <main id="admin-dashboard-content" tabindex="-1" class="admin-dashboard-content min-w-0 flex-1 pt-5" @if(isset($mobileDrawer)) x-bind:inert="sidebarOpen" @endif>
            @if (isset($status) && trim((string) $status) !== '')
                <div class="mb-5">{{ $status }}</div>
            @endif
            {{ $content ?? $slot }}
        </main>
    </div>
</div>
