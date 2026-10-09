@props([
    'activeTab' => 'stats',
    'activeNavigationId' => null,
    'title' => 'Admin Dashboard',
])

@php
    $linkedUser = auth()->user();
    $linkedNavigation = app(\App\Services\Admin\AdminDashboardData::class)->navigationFor($activeTab, $linkedUser, $activeNavigationId);
    $linkedActiveGroup = collect($linkedNavigation['groups'])->first(fn (array $group) => collect($group['items'])->contains('active', true));
@endphp

<div x-data="dashboardNavigation()" class="admin-dashboard-shell min-h-screen bg-admin-paper text-admin-ink">
    <x-admin-dashboard.shell
        data-return-target-highlight-class="admin-return-target-highlight"
    >
        <x-slot:sidebar>
            <div class="flex h-full min-h-0 flex-col border-r border-admin-line bg-admin-surface">
                <a href="{{ route('admin.dashboard') }}" class="flex min-h-20 items-center px-6 text-base font-semibold text-admin-ink">LHIE STORE</a>
                <x-admin-dashboard.navigation :groups="$linkedNavigation['groups']" class="min-h-0 flex-1 overflow-y-auto px-3 pb-6" />
                <div class="border-t border-admin-line px-6 py-4 text-xs text-admin-muted">
                    <p class="break-words font-medium text-admin-ink">{{ $linkedUser->name }}</p>
                    <p>{{ ucfirst($linkedUser->role?->value ?? '') }}</p>
                </div>
            </div>
        </x-slot:sidebar>

        <x-slot:topbar>
            <x-admin-dashboard.topbar>
                <x-slot:menu>
                    <button x-cloak x-ref="sidebarTrigger" type="button" aria-label="Buka navigasi admin" aria-haspopup="dialog" aria-controls="admin-dashboard-drawer" aria-expanded="false" x-bind:aria-expanded="sidebarOpen" x-on:click="openSidebar($event.currentTarget)" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-admin-line bg-admin-surface text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus lg:hidden">
                        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                </x-slot:menu>
                <x-slot:pageContext>
                    <p class="text-xs font-medium text-admin-muted">{{ $linkedActiveGroup['label'] ?? 'Admin' }}</p>
                    <h1 class="text-lg font-semibold text-admin-ink sm:text-xl">{{ $title }}</h1>
                </x-slot:pageContext>
                <x-slot:actions>
                    <x-admin-dashboard.button :href="route('admin.dashboard', ['tab' => $activeTab])" variant="neutral" size="sm">Kembali ke dashboard</x-admin-dashboard.button>
                    <x-theme-toggle class="h-11 w-11 shrink-0 rounded-lg border border-admin-line bg-admin-surface text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus" />
                </x-slot:actions>
                <x-slot:identity>
                    <p class="truncate font-medium text-admin-ink">{{ $linkedUser->name }}</p>
                    <p>{{ ucfirst($linkedUser->role?->value ?? '') }}</p>
                </x-slot:identity>
            </x-admin-dashboard.topbar>
        </x-slot:topbar>

        <x-slot:content>{{ $slot }}</x-slot:content>

        <x-slot:mobileDrawer>
            <x-admin-dashboard.mobile-drawer>
                <x-slot:close>
                    <button type="button" x-on:click="closeSidebar()" aria-label="Tutup navigasi" class="flex h-11 w-11 items-center justify-center rounded-lg text-xl text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus">×</button>
                </x-slot:close>
                <x-admin-dashboard.navigation :groups="$linkedNavigation['groups']" id-prefix="admin-linked-drawer-navigation" label="Navigasi dashboard" />
            </x-admin-dashboard.mobile-drawer>
        </x-slot:mobileDrawer>
    </x-admin-dashboard.shell>
</div>
