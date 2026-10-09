<details x-show="!navigationReady" class="border-b border-admin-line bg-admin-surface px-4 py-3 lg:hidden">
    <summary class="min-h-11 cursor-pointer py-3 text-sm font-medium text-admin-ink">Navigasi dashboard</summary>
    <x-admin-dashboard.navigation :groups="$navigation['groups']" id-prefix="admin-fallback-navigation" label="Navigasi dashboard" />
    @if ($canManageProducts)
        <a href="{{ route('admin.dashboard', [...request()->query(), 'tab' => 'produk', 'product_form' => 'create']) }}" class="flex min-h-11 items-center px-3 text-sm font-medium text-admin-accent">Tambah Produk</a>
    @endif
</details>

<x-admin-dashboard.mobile-drawer label="Navigasi admin">
    <x-slot:close>
        <button type="button" x-on:click="closeSidebar()" aria-label="Tutup navigasi" class="flex h-11 w-11 items-center justify-center rounded-lg text-xl text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus">×</button>
    </x-slot:close>
    <x-admin-dashboard.navigation :groups="$navigation['groups']" enhance-tabs id-prefix="admin-drawer-navigation" label="Navigasi dashboard" />
    @if ($canManageProducts)
        <a href="{{ route('admin.dashboard', [...request()->query(), 'tab' => 'produk', 'product_form' => 'create']) }}" x-on:click.prevent="goToTab('produk', true)" class="mt-6 flex min-h-11 items-center rounded-lg bg-admin-accent px-3 text-sm font-medium text-white focus-visible:outline-2 focus-visible:outline-admin-focus">Tambah Produk</a>
    @endif
</x-admin-dashboard.mobile-drawer>
