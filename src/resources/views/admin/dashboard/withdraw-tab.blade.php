<div id="admin-section-withdraw" data-admin-return-target tabindex="-1" x-cloak x-show="activeTab === 'withdraw'" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold uppercase tracking-wider text-neo-ink">Withdraw Order Status</h2>
            <p class="mt-1 text-xs font-semibold text-brand-muted">Monitoring read-only pencairan KlikQRIS. Tidak ada aksi initiate, upload, retry, atau mutasi.</p>
        </div>
        @if ($withdrawTab['stale'])
            <p class="text-xs font-black uppercase tracking-wide text-neo-warning-text">Data mungkin stale; waktu fetch tidak tersedia</p>
        @elseif ($withdrawTab['last_fetched_at_label'])
            <p class="text-xs font-black uppercase tracking-wide text-brand-muted">Diambil {{ $withdrawTab['last_fetched_at_label'] }}</p>
        @endif
    </div>

    @if ($withdrawTab['notice'])
        <x-admin-dashboard.notice :tone="$withdrawTab['notice']['tone']" role="status">
            <span class="font-black">{{ $withdrawTab['notice']['title'] }}:</span>
            {{ $withdrawTab['notice']['message'] }}
        </x-admin-dashboard.notice>
    @endif

    @if ($withdrawTab['can_view'])
        <form method="get" action="{{ route('admin.dashboard') }}" class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white p-4 shadow-neo-sm">
            <input type="hidden" name="tab" value="withdraw">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <label class="block lg:col-span-1">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Status</span>
                    <select name="status" class="admin-dashboard-input w-full">
                        <option value="">Semua status</option>
                        <option value="success" @selected($withdrawTab['filters']['status'] === 'success')>Success</option>
                        <option value="pending" @selected($withdrawTab['filters']['status'] === 'pending')>Pending</option>
                    </select>
                </label>
                <label class="block sm:col-span-2 lg:col-span-2">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Order ID</span>
                    <input type="text" name="order_id" value="{{ $withdrawTab['filters']['order_id'] }}" maxlength="120" class="admin-dashboard-input w-full" autocomplete="off">
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Mulai</span>
                    <input type="date" name="start_date" value="{{ $withdrawTab['filters']['start_date'] }}" class="admin-dashboard-input w-full">
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Sampai</span>
                    <input type="date" name="end_date" value="{{ $withdrawTab['filters']['end_date'] }}" class="admin-dashboard-input w-full">
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Limit</span>
                    <input type="number" name="limit" value="{{ $withdrawTab['filters']['limit'] }}" min="1" max="1000" inputmode="numeric" class="admin-dashboard-input w-full">
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold text-brand-muted">Filter tersimpan di URL dan dapat dibookmark.</p>
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-neo-yellow px-4 py-2 text-xs font-black uppercase tracking-wide text-neo-ink shadow-neo-sm">Terapkan filter</button>
            </div>
        </form>

        @if ($withdrawTab['state'] === 'ready')
            <div class="space-y-3 md:hidden">
                @forelse ($withdrawTab['items'] as $item)
                    <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="rounded-[1.125rem]">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-[10px] font-bold uppercase tracking-wider text-brand-muted">{{ $item['order_id'] }}</p>
                                <p class="mt-1 font-display text-base font-bold text-neo-ink">{{ $item['net_amount_settled_label'] }}</p>
                            </div>
                            <x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-3 border-t-2 border-brand-border pt-3 text-sm">
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Original</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $item['original_amount_label'] }}</dd></div>
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Paid unik</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $item['amount_paid_uniq_label'] }}</dd></div>
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">MDR</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $item['mdr_label'] }}</dd></div>
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Kode withdraw</dt><dd class="mt-0.5 break-words font-bold text-neo-ink">{{ $item['withdrawal_code'] }}</dd></div>
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Paid</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $item['transaction_paid_at'] }}</dd></div>
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Diminta</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $item['withdrawal_requested_at'] }}</dd></div>
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Diproses</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $item['withdrawal_processed_at'] }}</dd></div>
                            <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Bukti</dt><dd class="mt-0.5 font-bold text-neo-ink">
                                @if ($item['proof_url'])
                                    <a href="{{ $item['proof_url'] }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $item['proof_label'] }}</a>
                                @else
                                    <span>{{ $item['proof_label'] }}</span>
                                @endif
                            </dd></div>
                        </dl>
                    </x-admin-dashboard.card>
                @empty
                    <x-admin-dashboard.empty-state title="Belum ada data withdraw pada filter ini." />
                @endforelse
            </div>

            <div class="hidden overflow-x-auto rounded-[1.125rem] border-[3px] border-neo-ink bg-white shadow-neo-md md:block">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Status withdraw order KlikQRIS</caption>
                    <thead class="border-b-[3px] border-neo-ink bg-[#f1f5f9] text-[10px] font-black uppercase tracking-wide text-brand-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3">Order ID</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3">Original</th>
                            <th scope="col" class="px-4 py-3">Paid unik</th>
                            <th scope="col" class="px-4 py-3">MDR</th>
                            <th scope="col" class="px-4 py-3">Net settled</th>
                            <th scope="col" class="px-4 py-3">Kode</th>
                            <th scope="col" class="px-4 py-3">Paid / diminta / diproses</th>
                            <th scope="col" class="px-4 py-3">Bukti</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y-2 divide-brand-border">
                        @forelse ($withdrawTab['items'] as $item)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-black text-neo-ink">{{ $item['order_id'] }}</td>
                                <td class="px-4 py-3"><x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge></td>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $item['original_amount_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $item['amount_paid_uniq_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $item['mdr_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-black text-neo-ink">{{ $item['net_amount_settled_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $item['withdrawal_code'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs font-semibold text-neo-ink">
                                    <div>Paid: {{ $item['transaction_paid_at'] }}</div>
                                    <div>Diminta: {{ $item['withdrawal_requested_at'] }}</div>
                                    <div>Diproses: {{ $item['withdrawal_processed_at'] }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-bold text-neo-ink">
                                    @if ($item['proof_url'])
                                        <a href="{{ $item['proof_url'] }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $item['proof_label'] }}</a>
                                    @else
                                        <span>{{ $item['proof_label'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-8 text-center font-bold text-brand-muted">Belum ada data withdraw pada filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($withdrawTab['pagination']['has_pages'])
                <nav class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-sm" aria-label="Pagination withdraw KlikQRIS">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs font-black uppercase tracking-wide text-brand-muted">
                            Halaman {{ $withdrawTab['pagination']['current_page'] }} dari {{ $withdrawTab['pagination']['last_page'] }}
                            <span class="normal-case tracking-normal">· {{ $withdrawTab['pagination']['from'] }}-{{ $withdrawTab['pagination']['to'] }} dari {{ $withdrawTab['pagination']['total'] }} data</span>
                        </p>
                        <div class="flex items-center gap-2">
                            @if ($withdrawTab['pagination']['previous_url'])
                                <x-admin-dashboard.button :href="$withdrawTab['pagination']['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                            @else
                                <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Sebelumnya</span>
                            @endif
                            @if ($withdrawTab['pagination']['next_url'])
                                <x-admin-dashboard.button :href="$withdrawTab['pagination']['next_url']" variant="warning" size="sm">Berikutnya</x-admin-dashboard.button>
                            @else
                                <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Berikutnya</span>
                            @endif
                        </div>
                    </div>
                </nav>
            @endif
        @endif
    @endif
</div>
