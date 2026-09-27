<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Ikon Brand</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-brand-bg text-neo-ink">
    <x-theme-toggle showLabel class="fixed right-4 top-4 z-30 rounded-neo-xl border-[3px] border-neo-ink bg-white px-3 text-xs font-black text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
    <main class="mx-auto max-w-4xl p-4 pt-20 sm:p-6">
        <a href="{{ route('admin.dashboard', ['tab' => 'produk']) }}" class="text-sm font-bold text-brand-slate-dark underline">← Kembali ke Produk</a>

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Katalog</p>
            <h1 class="mt-1 text-2xl font-black">🎨 Ikon Brand</h1>
            <p class="mt-2 text-sm font-semibold text-brand-muted">Ikon diambil dari emoji custom Telegram lewat menu 🎨 Ikon Brand di bot. Halaman ini mengatur nama brand, pola pencocokan, dan emoji cadangan saat emoji belum diambil.</p>

            @if (session('status'))
                <p class="mt-4 rounded-xl bg-neo-success-soft px-4 py-3 text-sm font-bold">{{ session('status') }}</p>
            @endif

            <div class="mt-5 space-y-3">
                @forelse ($brands as $brand)
                    <div class="rounded-xl border-2 border-brand-border bg-white p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-black">{{ $brand->fallback_emoji }} {{ $brand->label }}</p>
                            @if ($brand->custom_emoji_id)
                                <x-admin-dashboard.badge tone="success">Emoji Telegram tersimpan</x-admin-dashboard.badge>
                            @else
                                <x-admin-dashboard.badge tone="warning">Belum ada emoji</x-admin-dashboard.badge>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('admin.brand-icons.update', $brand) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                            @csrf
                            @method('PATCH')
                            <label class="grid gap-1">
                                <x-admin-dashboard.field-label for="label-{{ $brand->id }}">Nama brand</x-admin-dashboard.field-label>
                                <x-admin-dashboard.input id="label-{{ $brand->id }}" name="label" :value="$brand->label" maxlength="160" required />
                            </label>
                            <label class="grid gap-1">
                                <x-admin-dashboard.field-label for="emoji-{{ $brand->id }}">Emoji cadangan</x-admin-dashboard.field-label>
                                <x-admin-dashboard.input id="emoji-{{ $brand->id }}" name="fallback_emoji" :value="$brand->fallback_emoji" maxlength="32" required />
                            </label>
                            <label class="grid gap-1 sm:col-span-2">
                                <x-admin-dashboard.field-label for="patterns-{{ $brand->id }}">Pola nama (satu per baris)</x-admin-dashboard.field-label>
                                <x-admin-dashboard.textarea id="patterns-{{ $brand->id }}" name="patterns" rows="3" :value="implode(PHP_EOL, $brand->patterns ?? [])" required />
                            </label>
                            <label class="grid gap-1">
                                <x-admin-dashboard.field-label for="sort-{{ $brand->id }}">Urutan</x-admin-dashboard.field-label>
                                <x-admin-dashboard.input id="sort-{{ $brand->id }}" name="sort_order" type="number" :value="$brand->sort_order" min="0" required />
                            </label>
                            <div class="flex items-end">
                                <x-admin-dashboard.button type="submit" size="sm" class="w-full text-[11px] sm:w-auto">Simpan</x-admin-dashboard.button>
                            </div>
                        </form>

                        @if ($brand->custom_emoji_id)
                            <form method="POST" action="{{ route('admin.brand-icons.custom-emoji.destroy', $brand) }}" class="mt-2">
                                @csrf
                                @method('DELETE')
                                <x-admin-dashboard.button type="submit" variant="danger" size="sm" class="w-full text-[11px] sm:w-auto">🗑️ Hapus emoji Telegram</x-admin-dashboard.button>
                            </form>
                        @endif
                    </div>
                @empty
                    <x-admin-dashboard.empty-state title="Belum ada brand di registry." />
                @endforelse
            </div>
        </x-admin-dashboard.card>

        <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="mt-4 rounded-2xl">
            <h2 class="text-lg font-black">➕ Tambah Brand</h2>
            <form method="POST" action="{{ route('admin.brand-icons.store') }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                @csrf
                <label class="grid gap-1">
                    <x-admin-dashboard.field-label for="new-label">Nama brand</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="new-label" name="label" maxlength="160" required />
                </label>
                <label class="grid gap-1">
                    <x-admin-dashboard.field-label for="new-emoji">Emoji cadangan</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="new-emoji" name="fallback_emoji" maxlength="32" required />
                </label>
                <label class="grid gap-1 sm:col-span-2">
                    <x-admin-dashboard.field-label for="new-patterns">Pola nama (satu per baris)</x-admin-dashboard.field-label>
                    <x-admin-dashboard.textarea id="new-patterns" name="patterns" rows="3" required />
                </label>
                <label class="grid gap-1">
                    <x-admin-dashboard.field-label for="new-sort">Urutan</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="new-sort" name="sort_order" type="number" value="0" min="0" required />
                </label>
                <div class="flex items-end">
                    <x-admin-dashboard.button type="submit" size="sm" class="w-full text-[11px] sm:w-auto">Tambah brand</x-admin-dashboard.button>
                </div>
            </form>
        </x-admin-dashboard.card>
    </main>
</body>
</html>
