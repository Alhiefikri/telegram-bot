<div id="admin-section-history" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium text-admin-muted">Insight · read-only</p>
            <h2 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Riwayat KlikQRIS</h2>
            <p class="mt-1 text-sm leading-6 text-admin-muted">Observasi provider untuk rekonsiliasi. Halaman ini tidak mengubah Order atau Payment lokal.</p>
        </div>
        @if ($historyTab['stale'])
            <p class="text-xs font-medium text-neo-warning-text">Data mungkin stale; waktu fetch tidak tersedia</p>
        @elseif ($historyTab['last_fetched_at_label'])
            <p class="text-xs font-medium text-admin-muted">Diambil {{ $historyTab['last_fetched_at_label'] }}</p>
        @endif
    </div>

    @if ($historyTab['notice'])
        <x-admin-dashboard.notice :tone="$historyTab['notice']['tone']" role="status">
            <span class="font-semibold">{{ $historyTab['notice']['title'] }}:</span>
            {{ $historyTab['notice']['message'] }}
        </x-admin-dashboard.notice>
    @endif

    @if ($historyTab['state'] === 'ready')
        <div class="space-y-3 md:hidden">
            @forelse ($historyTab['items'] as $item)
                <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
                    <div class="flex min-w-0 items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-xs font-medium text-admin-muted">{{ $item['order_id'] }}</p>
                            <p class="mt-1 text-lg font-semibold text-admin-ink">{{ $item['amount_label'] }}</p>
                        </div>
                        <x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-admin-line pt-3 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-admin-muted">Dibuat</dt>
                            <dd class="mt-0.5 font-medium text-admin-ink">{{ $item['created_at_label'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-admin-muted">Via</dt>
                            <dd class="mt-0.5 font-medium text-admin-ink">{{ $item['via'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-admin-muted">Tipe</dt>
                            <dd class="mt-0.5 font-medium text-admin-ink">{{ $item['type'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-admin-muted">Catatan</dt>
                            <dd class="mt-0.5 break-words font-medium text-admin-ink">{{ $item['note'] }}</dd>
                        </div>
                    </dl>
                    @if ($item['local_match'])
                        <a href="{{ $item['local_order_url'] }}" class="mt-3 inline-flex text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Detail order lokal ({{ $item['local_invoice'] }})</a>
                    @else
                        <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">
                            {{ $item['mismatch_message'] }}
                        </x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>
            @empty
                <x-admin-dashboard.empty-state title="Belum ada transaksi pada halaman ini." />
            @endforelse
        </div>

        <div class="hidden overflow-x-auto rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface md:block">
            <table class="min-w-full text-left text-sm">
                <caption class="sr-only">Riwayat transaksi KlikQRIS</caption>
                <thead class="border-b border-admin-line bg-admin-surface-muted text-xs font-medium text-admin-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Provider order ID</th>
                        <th scope="col" class="px-4 py-3 font-medium">Dibuat</th>
                        <th scope="col" class="px-4 py-3 font-medium">Jumlah</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">Tipe / Via</th>
                        <th scope="col" class="px-4 py-3 font-medium">Catatan</th>
                        <th scope="col" class="px-4 py-3 font-medium">Order lokal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-admin-line">
                    @forelse ($historyTab['items'] as $item)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-medium text-admin-ink">{{ $item['order_id'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $item['created_at_label'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-semibold text-admin-ink">{{ $item['amount_label'] }}</td>
                            <td class="px-4 py-3"><x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge></td>
                            <td class="px-4 py-3 font-medium text-admin-ink">{{ $item['type'] }} / {{ $item['via'] }}</td>
                            <td class="max-w-xs break-words px-4 py-3 font-medium text-admin-ink">{{ $item['note'] }}</td>
                            <td class="px-4 py-3">
                                @if ($item['local_match'])
                                    <a href="{{ $item['local_order_url'] }}" class="text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Detail ({{ $item['local_invoice'] }})</a>
                                @else
                                    <span class="text-sm font-medium text-neo-danger-text">Mismatch lokal</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-sm font-medium text-admin-muted">Belum ada transaksi pada halaman ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($historyTab['pagination']['has_pages'])
            <nav class="rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-3" aria-label="Pagination riwayat KlikQRIS">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-medium text-admin-muted">
                        Halaman {{ $historyTab['pagination']['current_page'] }} dari {{ $historyTab['pagination']['last_page'] }}
                        <span>· {{ $historyTab['pagination']['from'] }}-{{ $historyTab['pagination']['to'] }} dari {{ $historyTab['pagination']['total'] }} transaksi</span>
                    </p>
                    <div class="flex items-center gap-2">
                        @if ($historyTab['pagination']['previous_url'])
                            <x-admin-dashboard.button :href="$historyTab['pagination']['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Sebelumnya</span>
                        @endif
                        @if ($historyTab['pagination']['next_url'])
                            <x-admin-dashboard.button :href="$historyTab['pagination']['next_url']" variant="warning" size="sm">Berikutnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Berikutnya</span>
                        @endif
                    </div>
                </div>
            </nav>
        @endif
    @endif
</div>
