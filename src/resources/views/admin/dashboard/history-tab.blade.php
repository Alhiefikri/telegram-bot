<div id="admin-section-history" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold uppercase tracking-wider text-neo-ink">Riwayat KlikQRIS</h2>
            <p class="mt-1 text-xs font-semibold text-brand-muted">Observasi provider untuk rekonsiliasi. Halaman ini tidak mengubah Order atau Payment lokal.</p>
        </div>
        @if ($historyTab['stale'])
            <p class="text-xs font-black uppercase tracking-wide text-neo-warning-text">Data mungkin stale; waktu fetch tidak tersedia</p>
        @elseif ($historyTab['last_fetched_at_label'])
            <p class="text-xs font-black uppercase tracking-wide text-brand-muted">Diambil {{ $historyTab['last_fetched_at_label'] }}</p>
        @endif
    </div>

    @if ($historyTab['notice'])
        <x-admin-dashboard.notice :tone="$historyTab['notice']['tone']" role="status">
            <span class="font-black">{{ $historyTab['notice']['title'] }}:</span>
            {{ $historyTab['notice']['message'] }}
        </x-admin-dashboard.notice>
    @endif

    @if ($historyTab['state'] === 'ready')
        <div class="space-y-3 md:hidden">
            @forelse ($historyTab['items'] as $item)
                <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="rounded-[1.125rem]">
                    <div class="flex min-w-0 items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[10px] font-bold uppercase tracking-wider text-brand-muted">{{ $item['order_id'] }}</p>
                            <p class="mt-1 font-display text-base font-bold text-neo-ink">{{ $item['amount_label'] }}</p>
                        </div>
                        <x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-3 border-t-2 border-brand-border pt-3 text-sm">
                        <div>
                            <dt class="text-[9px] font-bold uppercase text-brand-muted">Dibuat</dt>
                            <dd class="mt-0.5 font-bold text-neo-ink">{{ $item['created_at_label'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[9px] font-bold uppercase text-brand-muted">Via</dt>
                            <dd class="mt-0.5 font-bold text-neo-ink">{{ $item['via'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[9px] font-bold uppercase text-brand-muted">Tipe</dt>
                            <dd class="mt-0.5 font-bold text-neo-ink">{{ $item['type'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[9px] font-bold uppercase text-brand-muted">Catatan</dt>
                            <dd class="mt-0.5 break-words font-bold text-neo-ink">{{ $item['note'] }}</dd>
                        </div>
                    </dl>
                    @if ($item['local_match'])
                        <a href="{{ $item['local_order_url'] }}" class="mt-3 inline-flex text-xs font-black text-neo-ink underline">Detail order lokal ({{ $item['local_invoice'] }})</a>
                    @else
                        <x-admin-dashboard.notice tone="warning" class="mt-3 rounded-xl text-xs">
                            {{ $item['mismatch_message'] }}
                        </x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>
            @empty
                <x-admin-dashboard.empty-state title="Belum ada transaksi pada halaman ini." />
            @endforelse
        </div>

        <div class="hidden overflow-x-auto rounded-[1.125rem] border-[3px] border-neo-ink bg-white shadow-neo-md md:block">
            <table class="min-w-full text-left text-sm">
                <caption class="sr-only">Riwayat transaksi KlikQRIS</caption>
                <thead class="border-b-[3px] border-neo-ink bg-[#f1f5f9] text-[10px] font-black uppercase tracking-wide text-brand-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3">Provider order ID</th>
                        <th scope="col" class="px-4 py-3">Dibuat</th>
                        <th scope="col" class="px-4 py-3">Jumlah</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Tipe / Via</th>
                        <th scope="col" class="px-4 py-3">Catatan</th>
                        <th scope="col" class="px-4 py-3">Order lokal</th>
                    </tr>
                </thead>
                <tbody class="divide-y-2 divide-brand-border">
                    @forelse ($historyTab['items'] as $item)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-black text-neo-ink">{{ $item['order_id'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $item['created_at_label'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-black text-neo-ink">{{ $item['amount_label'] }}</td>
                            <td class="px-4 py-3"><x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge></td>
                            <td class="px-4 py-3 font-semibold text-neo-ink">{{ $item['type'] }} / {{ $item['via'] }}</td>
                            <td class="max-w-xs break-words px-4 py-3 font-semibold text-neo-ink">{{ $item['note'] }}</td>
                            <td class="px-4 py-3">
                                @if ($item['local_match'])
                                    <a href="{{ $item['local_order_url'] }}" class="font-black text-neo-ink underline">Detail ({{ $item['local_invoice'] }})</a>
                                @else
                                    <span class="font-bold text-neo-danger-text">Mismatch lokal</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center font-bold text-brand-muted">Belum ada transaksi pada halaman ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($historyTab['pagination']['has_pages'])
            <nav class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-sm" aria-label="Pagination riwayat KlikQRIS">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-black uppercase tracking-wide text-brand-muted">
                        Halaman {{ $historyTab['pagination']['current_page'] }} dari {{ $historyTab['pagination']['last_page'] }}
                        <span class="normal-case tracking-normal">· {{ $historyTab['pagination']['from'] }}-{{ $historyTab['pagination']['to'] }} dari {{ $historyTab['pagination']['total'] }} transaksi</span>
                    </p>
                    <div class="flex items-center gap-2">
                        @if ($historyTab['pagination']['previous_url'])
                            <x-admin-dashboard.button :href="$historyTab['pagination']['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Sebelumnya</span>
                        @endif
                        @if ($historyTab['pagination']['next_url'])
                            <x-admin-dashboard.button :href="$historyTab['pagination']['next_url']" variant="warning" size="sm">Berikutnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Berikutnya</span>
                        @endif
                    </div>
                </div>
            </nav>
        @endif
    @endif
</div>
