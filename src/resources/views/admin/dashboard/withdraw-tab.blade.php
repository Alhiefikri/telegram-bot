<div id="admin-section-withdraw" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium text-admin-muted">Insight · read-only</p>
            <h2 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Withdraw Order Status</h2>
            <p class="mt-1 text-sm leading-6 text-admin-muted">Monitoring read-only pencairan KlikQRIS. Tidak ada aksi initiate, upload, retry, atau mutasi.</p>
        </div>
        @if ($withdrawTab['stale'])
            <p class="text-xs font-medium text-neo-warning-text">Data mungkin stale; waktu fetch tidak tersedia</p>
        @elseif ($withdrawTab['last_fetched_at_label'])
            <p class="text-xs font-medium text-admin-muted">Diambil {{ $withdrawTab['last_fetched_at_label'] }}</p>
        @endif
    </div>

    @if ($withdrawTab['notice'])
        <x-admin-dashboard.notice :tone="$withdrawTab['notice']['tone']" role="status">
            <span class="font-semibold">{{ $withdrawTab['notice']['title'] }}:</span>
            {{ $withdrawTab['notice']['message'] }}
        </x-admin-dashboard.notice>
    @endif

    @if ($withdrawTab['can_view'])
        <form method="get" action="{{ route('admin.dashboard') }}" class="rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-4" aria-label="Filter withdraw">
            <input type="hidden" name="tab" value="withdraw">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <div class="lg:col-span-1">
                    <x-admin-dashboard.field-label for="withdraw-filter-status">Status</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="withdraw-filter-status" name="status" class="mt-1">
                        <option value="">Semua status</option>
                        <option value="success" @selected($withdrawTab['filters']['status'] === 'success')>Success</option>
                        <option value="pending" @selected($withdrawTab['filters']['status'] === 'pending')>Pending</option>
                    </x-admin-dashboard.select>
                </div>
                <div class="sm:col-span-2 lg:col-span-2">
                    <x-admin-dashboard.field-label for="withdraw-filter-order-id">Order ID</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="withdraw-filter-order-id" name="order_id" value="{{ $withdrawTab['filters']['order_id'] }}" maxlength="120" class="mt-1" autocomplete="off" />
                </div>
                <div>
                    <x-admin-dashboard.field-label for="withdraw-filter-start-date">Mulai</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="withdraw-filter-start-date" name="start_date" type="date" value="{{ $withdrawTab['filters']['start_date'] }}" class="mt-1" />
                </div>
                <div>
                    <x-admin-dashboard.field-label for="withdraw-filter-end-date">Sampai</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="withdraw-filter-end-date" name="end_date" type="date" value="{{ $withdrawTab['filters']['end_date'] }}" class="mt-1" />
                </div>
                <div>
                    <x-admin-dashboard.field-label for="withdraw-filter-limit">Limit</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="withdraw-filter-limit" name="limit" type="number" value="{{ $withdrawTab['filters']['limit'] }}" min="1" max="1000" inputmode="numeric" class="mt-1" />
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-medium text-admin-muted">Filter tersimpan di URL dan dapat dibookmark.</p>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full sm:w-auto">Terapkan filter</x-admin-dashboard.button>
            </div>
        </form>

        @if ($withdrawTab['state'] === 'ready')
            <div class="space-y-3 md:hidden">
                @forelse ($withdrawTab['items'] as $item)
                    <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-xs font-medium text-admin-muted">{{ $item['order_id'] }}</p>
                                <p class="mt-1 text-lg font-semibold text-admin-ink">{{ $item['net_amount_settled_label'] }}</p>
                            </div>
                            <x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-admin-line pt-3 text-sm">
                            <div><dt class="text-xs font-medium text-admin-muted">Original</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $item['original_amount_label'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Paid unik</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $item['amount_paid_uniq_label'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">MDR</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $item['mdr_label'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Kode withdraw</dt><dd class="mt-0.5 break-words font-mono text-xs font-medium text-admin-ink">{{ $item['withdrawal_code'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Paid</dt><dd class="mt-0.5 font-medium text-admin-ink">{{ $item['transaction_paid_at'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Diminta</dt><dd class="mt-0.5 font-medium text-admin-ink">{{ $item['withdrawal_requested_at'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Diproses</dt><dd class="mt-0.5 font-medium text-admin-ink">{{ $item['withdrawal_processed_at'] }}</dd></div>
                            <div><dt class="text-xs font-medium text-admin-muted">Bukti</dt><dd class="mt-0.5 font-medium text-admin-ink">
                                @if ($item['proof_url'])
                                    <a href="{{ $item['proof_url'] }}" target="_blank" rel="noopener noreferrer" class="text-admin-accent-strong underline-offset-4 hover:underline">{{ $item['proof_label'] }}</a>
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

            <div class="hidden overflow-x-auto rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface md:block">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Status withdraw order KlikQRIS</caption>
                    <thead class="border-b border-admin-line bg-admin-surface-muted text-xs font-medium text-admin-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Order ID</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 font-medium">Original</th>
                            <th scope="col" class="px-4 py-3 font-medium">Paid unik</th>
                            <th scope="col" class="px-4 py-3 font-medium">MDR</th>
                            <th scope="col" class="px-4 py-3 font-medium">Net settled</th>
                            <th scope="col" class="px-4 py-3 font-medium">Kode</th>
                            <th scope="col" class="px-4 py-3 font-medium">Paid / diminta / diproses</th>
                            <th scope="col" class="px-4 py-3 font-medium">Bukti</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-admin-line">
                        @forelse ($withdrawTab['items'] as $item)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-medium text-admin-ink">{{ $item['order_id'] }}</td>
                                <td class="px-4 py-3"><x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge></td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $item['original_amount_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $item['amount_paid_uniq_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $item['mdr_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-admin-ink">{{ $item['net_amount_settled_label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-medium text-admin-ink">{{ $item['withdrawal_code'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs font-medium text-admin-ink">
                                    <div>Paid: {{ $item['transaction_paid_at'] }}</div>
                                    <div>Diminta: {{ $item['withdrawal_requested_at'] }}</div>
                                    <div>Diproses: {{ $item['withdrawal_processed_at'] }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-admin-ink">
                                    @if ($item['proof_url'])
                                        <a href="{{ $item['proof_url'] }}" target="_blank" rel="noopener noreferrer" class="text-admin-accent-strong underline-offset-4 hover:underline">{{ $item['proof_label'] }}</a>
                                    @else
                                        <span>{{ $item['proof_label'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-8 text-center text-sm font-medium text-admin-muted">Belum ada data withdraw pada filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($withdrawTab['pagination']['has_pages'])
                <nav class="rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-3" aria-label="Pagination withdraw KlikQRIS">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs font-medium text-admin-muted">
                            Halaman {{ $withdrawTab['pagination']['current_page'] }} dari {{ $withdrawTab['pagination']['last_page'] }}
                            <span>· {{ $withdrawTab['pagination']['from'] }}-{{ $withdrawTab['pagination']['to'] }} dari {{ $withdrawTab['pagination']['total'] }} data</span>
                        </p>
                        <div class="flex items-center gap-2">
                            @if ($withdrawTab['pagination']['previous_url'])
                                <x-admin-dashboard.button :href="$withdrawTab['pagination']['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                            @else
                                <span class="inline-flex min-h-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Sebelumnya</span>
                            @endif
                            @if ($withdrawTab['pagination']['next_url'])
                                <x-admin-dashboard.button :href="$withdrawTab['pagination']['next_url']" variant="warning" size="sm">Berikutnya</x-admin-dashboard.button>
                            @else
                                <span class="inline-flex min-h-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Berikutnya</span>
                            @endif
                        </div>
                    </div>
                </nav>
            @endif
        @endif
    @endif
</div>
