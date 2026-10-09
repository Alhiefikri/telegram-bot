@props([
    'id' => 'admin-dashboard-drawer',
    'label' => 'Navigasi admin',
])

<div id="{{ $id }}" {{ $attributes->merge(['x-cloak' => true]) }}>
    <div class="fixed inset-0 z-40 bg-slate-950/40 lg:hidden" aria-hidden="true"></div>
    <aside class="fixed inset-y-0 left-0 z-50 flex w-[min(320px,calc(100vw-32px))] flex-col bg-admin-surface shadow-admin-lg lg:hidden" role="dialog" aria-modal="true" aria-label="{{ $label }}">
        <div class="flex min-h-16 items-center justify-between border-b border-admin-line px-4">
            @if (isset($close) && trim((string) $close) !== '')
                {{ $close }}
            @endif
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto p-4">
            {{ $slot }}
        </div>
    </aside>
</div>
