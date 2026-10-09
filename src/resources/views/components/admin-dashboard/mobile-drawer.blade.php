@props([
    'id' => 'admin-dashboard-drawer',
    'label' => 'Navigasi admin',
])

<div {{ $attributes->class(['lg:hidden'])->merge(['x-cloak' => true, 'x-show' => 'sidebarOpen']) }}>
    <div class="fixed inset-0 z-40 bg-slate-950/40" x-on:click="closeSidebar()" aria-hidden="true"></div>
    <aside id="{{ $id }}" x-ref="sidebarDialog" tabindex="-1" class="admin-navigation-drawer fixed inset-y-0 left-0 z-50 flex w-[min(320px,calc(100vw-32px))] flex-col bg-admin-surface shadow-admin-lg" role="dialog" aria-modal="true" aria-label="{{ $label }}"
        x-on:keydown.escape.stop.prevent="closeSidebar()"
        x-on:keydown.tab="trapModalFocus($event)"
        x-on:focusin.window="if (sidebarOpen) keepModalFocus($event, @js($id))"
        x-on:click="handleSidebarNavigation($event)">
        <div class="flex min-h-16 items-center justify-between border-b border-admin-line px-4">
            <p class="font-semibold text-admin-ink">{{ $label }}</p>
            @if (isset($close) && trim((string) $close) !== '')
                {{ $close }}
            @endif
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto p-4">
            {{ $slot }}
        </div>
    </aside>
</div>
