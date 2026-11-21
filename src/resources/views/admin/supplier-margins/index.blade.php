@extends('admin.supplier-margins.layout')

@section('margin-content')
    @php($drafts = collect(old('edits', []))->take(25)->filter(fn ($edit) => is_array($edit) && (is_int($edit['id'] ?? null) || is_string($edit['id'] ?? null)))->keyBy('id'))
    <p class="text-sm leading-6 text-admin-muted">Bandingkan harga jual reguler IDR dengan estimasi modal 1 unit supplier, sebelum biaya transaksi yang ditanggung toko. Mengisi target hanya mengubah saran di halaman ini, bukan aturan harga produk.</p>
    <x-admin-dashboard.card padding="compact" shadow="sm">
        <form method="GET" action="{{ route('admin.suppliers.margins.index') }}" class="flex flex-wrap items-end gap-4">
            <label class="grid gap-1 text-sm font-medium">Produk terhubung ke
                <select name="supplier" class="min-h-11 max-w-64 rounded-lg border border-admin-line bg-admin-surface px-3 text-admin-ink">
                    <option value="">Semua supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(($filters['supplier'] ?? null) === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm font-medium">Target untung / unit (Rp)
                <x-admin-dashboard.input name="target" type="number" min="0" max="1000000000" step="1" inputmode="numeric" :value="$filters['target']" />
            </label>
            <label class="grid gap-1 text-sm font-medium">Bulatkan saran ke atas (Rp)
                <select name="rounding" class="min-h-11 rounded-lg border border-admin-line bg-admin-surface px-3 text-admin-ink">
                    @foreach([1, 100, 500, 1000] as $step)
                        <option value="{{ $step }}" @selected($filters['rounding'] === $step)>{{ $step }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex min-h-11 items-center gap-2 text-sm font-medium">
                <input type="checkbox" name="action" value="1" @checked(!empty($filters['action']))>
                Perlu Tindakan
            </label>
            <x-admin-dashboard.button type="submit" variant="neutral">Terapkan filter</x-admin-dashboard.button>
        </form>
    </x-admin-dashboard.card>

    <x-admin-dashboard.card padding="compact" shadow="sm">
        <form method="POST" action="{{ route('admin.suppliers.margins.review', $query) }}" class="grid gap-4" x-data>
            @csrf
            <p class="text-sm leading-6 text-admin-muted">Prioritas ditampilkan dalam tiap halaman. Filter Perlu Tindakan menyembunyikan baris yang aman; lanjutkan ke halaman berikutnya untuk memeriksa produk lain. Target per produk mengikuti angka yang lebih tinggi antara target di atas dan syarat minimum produk.</p>
            <div class="min-w-0 overflow-x-auto" tabindex="0" role="region" aria-label="Tabel Margin Supplier">
                <table class="w-full min-w-fit text-left text-sm">
                    <thead>
                        <tr class="border-b border-admin-line text-admin-muted">
                            <th scope="col" class="p-3">Produk</th>
                            <th scope="col" class="p-3">Supplier acuan / modal</th>
                            <th scope="col" class="p-3">Harga jual saat ini</th>
                            <th scope="col" class="p-3">Selisih / unit</th>
                            <th scope="col" class="p-3">Harga jual baru</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php($editIndex = 0)
                        @forelse($rows as $row)
                            <tr class="border-b border-admin-line align-top" @if($row['editable']) x-data="supplierMarginRow(@js($row['cost'] === null ? null : (string) $row['cost']), @js($row['suggestion'] === null ? null : (string) $row['suggestion']))" @endif>
                                <th scope="row" class="p-3 font-medium">
                                    <a href="{{ route('admin.products.workspace', $row['id']) }}?section=price" class="text-admin-accent-strong underline-offset-4 hover:underline">{{ $row['name'] }}</a>
                                    @unless($row['active'])<span class="block text-xs text-admin-muted">Produk nonaktif</span>@endunless
                                </th>
                                <td class="p-3">
                                    <p>{{ $row['reference']['name'] ?? '—' }}</p>
                                    @if($row['cost'] !== null)<p class="font-medium"><x-admin-dashboard.margin-amount :amount="$row['cost']" /></p>@endif
                                    @if($synced = $row['reference']['synced_at'] ?? null)
                                        <time class="block text-xs text-admin-muted" datetime="{{ $synced->toISOString() }}">{{ $synced->timezone(config('app.timezone'))->format('d/m/Y H:i T') }}</time>
                                    @endif
                                    @if($row['stale'])<p class="font-semibold">Perlu refresh</p>@endif
                                    @if($row['cost'] === null)<p class="text-xs text-admin-muted">Modal belum terverifikasi</p>
                                    @elseif(! $row['reference']['acquirable'])<p class="text-xs text-admin-muted">Belum bisa dibeli</p>
                                    @elseif(! $row['reference']['eligible'])<p class="text-xs text-admin-muted">Harga belum lolos batas pembelian</p>@endif
                                </td>
                                <td class="whitespace-nowrap p-3"><x-admin-dashboard.margin-amount :amount="$row['price']" /></td>
                                <td class="whitespace-nowrap p-3">
                                    @if($row['delta'] !== null)
                                        <p class="font-semibold"><x-admin-dashboard.margin-amount :amount="$row['delta']" signed /></p>
                                        @if($row['delta'] < 0)<p class="text-xs font-semibold">Rugi</p>
                                        @elseif($row['delta'] < $row['target'])<p class="text-xs font-semibold">Di bawah target</p>@endif
                                    @else <p>—</p> @endif
                                    @if($row['fallback_delta'] !== null && $row['fallback_delta'] < $row['target'])
                                        <p class="text-xs text-admin-muted">Cadangan terendah: <x-admin-dashboard.margin-amount :amount="$row['fallback_delta']" signed /></p>
                                    @endif
                                </td>
                                <td class="p-3">
                                    @if($row['editable'])
                                        <input type="hidden" name="edits[{{ $editIndex }}][id]" value="{{ $row['id'] }}">
                                        <input type="hidden" name="edits[{{ $editIndex }}][version]" value="{{ $row['version'] }}">
                                        @php($draft = $drafts->get($row['id']))
                                        <x-admin-dashboard.input class="min-w-36" type="text" pattern="[0-9]+" maxlength="17" inputmode="numeric" name="edits[{{ $editIndex }}][price]" aria-label="Harga IDR baru untuk {{ $row['name'] }}" placeholder="Tetap {{ $row['price'] }}" :value="($draft['version'] ?? null) === $row['version'] && is_scalar($draft['price'] ?? null) ? $draft['price'] : null" x-ref="price" x-init="update()" x-on:input="update()" />
                                        @php($editIndex++)
                                        @if($row['suggestion'] !== null)
                                            <p class="mt-1 text-xs">Saran: <x-admin-dashboard.margin-amount :amount="$row['suggestion']" /></p>
                                            <button type="button" x-cloak x-on:click="apply()" class="min-h-11 text-sm font-medium text-admin-accent-strong underline">Pakai saran</button>
                                        @endif
                                        <p aria-live="polite" class="mt-1 text-xs text-admin-muted" x-text="delta"></p>
                                    @elseif($row['automatic'])
                                        <p class="text-admin-muted">Harga otomatis</p>
                                    @elseif($row['truncated'])
                                        <p class="text-admin-muted">Terlalu banyak mapping; edit dari produk.</p>
                                    @else
                                        <p class="text-admin-muted">Atur kebijakan harga dari produk.</p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-4 text-admin-muted">Tidak ada produk yang cocok pada halaman ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($rows->contains('editable', true))
                <div class="flex flex-wrap items-center gap-3">
                    <x-admin-dashboard.button type="submit" variant="primary">Tinjau &amp; Simpan</x-admin-dashboard.button>
                    <p class="text-sm text-admin-muted">Simpan perubahan sebelum berpindah halaman. Harga yang dikosongkan tidak berubah.</p>
                </div>
            @endif
        </form>
        <nav class="mt-4 flex flex-wrap gap-3" aria-label="Halaman Margin Supplier">
            @if($page->previousPageUrl())
                <x-admin-dashboard.button :href="$page->previousPageUrl()" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
            @endif
            @if($page->nextPageUrl())
                <x-admin-dashboard.button :href="$page->nextPageUrl()" variant="neutral" size="sm">Berikutnya</x-admin-dashboard.button>
            @endif
        </nav>
    </x-admin-dashboard.card>
@endsection
