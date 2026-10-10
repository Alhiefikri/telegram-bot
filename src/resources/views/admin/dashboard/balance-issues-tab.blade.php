<div id="admin-section-balance-issues" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium text-admin-muted">Keuangan · Owner</p>
            <h2 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Kompensasi top-up bermasalah</h2>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-admin-muted">Khusus Owner. Nominal yang dirilis selalu diambil dari record top-up asli; form tidak menerima nominal dari browser.</p>
        </div>
        <x-admin-dashboard.badge tone="danger" dot>Financial mutation</x-admin-dashboard.badge>
    </div>

    @if ($errors->balanceCompensation->any())
        <x-admin-dashboard.notice tone="danger" role="alert">
            {{ $errors->balanceCompensation->first() }}
        </x-admin-dashboard.notice>
    @endif

    <x-admin-dashboard.card as="section" padding="compact" shadow="sm">
        <form method="GET" action="{{ route('admin.dashboard') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-6" aria-label="Filter kompensasi top-up">
            <input type="hidden" name="tab" value="balance-issues">
            <div>
                <x-admin-dashboard.field-label for="balance-filter-telegram-id">Telegram ID</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="balance-filter-telegram-id" name="balance_telegram_id" value="{{ $balanceIssuesTab['filters']['telegram_id'] }}" inputmode="numeric" class="mt-1" placeholder="123456789" />
            </div>
            <div>
                <x-admin-dashboard.field-label for="balance-filter-username">Username</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="balance-filter-username" name="balance_username" value="{{ $balanceIssuesTab['filters']['username'] }}" class="mt-1" placeholder="username" />
            </div>
            <div>
                <x-admin-dashboard.field-label for="balance-filter-status">Status</x-admin-dashboard.field-label>
                <x-admin-dashboard.select id="balance-filter-status" name="balance_status" class="mt-1">
                    <option value="">Semua status bermasalah</option>
                    <option value="failed" @selected($balanceIssuesTab['filters']['status'] === 'failed')>Gagal</option>
                    <option value="recovery_pending" @selected($balanceIssuesTab['filters']['status'] === 'recovery_pending')>Recovery pending</option>
                    <option value="invalid_provider_result" @selected($balanceIssuesTab['filters']['status'] === 'invalid_provider_result')>Provider invalid</option>
                    <option value="expired" @selected($balanceIssuesTab['filters']['status'] === 'expired')>Kedaluwarsa</option>
                </x-admin-dashboard.select>
            </div>
            <div>
                <x-admin-dashboard.field-label for="balance-filter-age">Umur minimal</x-admin-dashboard.field-label>
                <x-admin-dashboard.select id="balance-filter-age" name="balance_age" class="mt-1">
                    <option value="">Semua umur</option>
                    <option value="24" @selected($balanceIssuesTab['filters']['age'] === '24')>24 jam</option>
                    <option value="168" @selected($balanceIssuesTab['filters']['age'] === '168')>7 hari</option>
                    <option value="720" @selected($balanceIssuesTab['filters']['age'] === '720')>30 hari</option>
                </x-admin-dashboard.select>
            </div>
            <div class="text-xs font-medium text-admin-muted xl:col-span-2">
                <x-admin-dashboard.field-label for="balance-filter-provider-reference">Referensi provider</x-admin-dashboard.field-label>
                <x-admin-dashboard.input id="balance-filter-provider-reference" name="balance_provider_reference" value="{{ $balanceIssuesTab['filters']['provider_reference'] }}" class="mt-1" placeholder="Reference exact" />
            </div>
            <div class="flex items-end gap-2 xl:col-span-6">
                <x-admin-dashboard.button type="submit" variant="primary" size="sm">Terapkan filter</x-admin-dashboard.button>
                <x-admin-dashboard.button :href="route('admin.dashboard', ['tab' => 'balance-issues'])" variant="neutral" size="sm">Reset</x-admin-dashboard.button>
            </div>
        </form>
    </x-admin-dashboard.card>

    @if ($balanceIssuesTab['state'] === 'ready')
        <div class="space-y-3 md:hidden">
            @forelse ($balanceIssuesTab['items'] as $item)
                <x-admin-dashboard.card as="article" padding="compact" shadow="sm" x-data="{ releaseOpen: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-admin-muted">Top-up #{{ $item['top_up_id'] }} · {{ $item['age_label'] }}</p>
                            <p class="mt-1 truncate text-base font-semibold text-admin-ink">{{ $item['customer_name'] }}</p>
                            <p class="text-xs font-medium text-admin-muted">Telegram {{ $item['telegram_id'] }} · {{ $item['username'] }}</p>
                        </div>
                        <x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-admin-line pt-3 text-sm">
                        <div><dt class="text-xs font-medium text-admin-muted">Nominal asli</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $item['amount_label'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Saldo saat ini</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $item['balance_current_label'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Dibuat</dt><dd class="mt-0.5 font-medium text-admin-ink">{{ $item['created_at_label'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Provider dibuat</dt><dd class="mt-0.5 font-medium text-admin-ink">{{ $item['provider_created_at_label'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Provider ref</dt><dd class="mt-0.5 break-all font-mono text-xs font-medium text-admin-ink">{{ $item['provider_reference'] }}</dd></div>
                        <div><dt class="text-xs font-medium text-admin-muted">Review</dt><dd class="mt-0.5 break-words font-medium text-admin-ink">{{ $item['review_reason'] }}</dd></div>
                    </dl>
                    @if ($item['eligible'])
                        <x-admin-dashboard.button type="button" variant="primary" size="sm" class="mt-4 w-full" x-on:click="releaseOpen = true">Release saldo</x-admin-dashboard.button>
                        @include('admin.dashboard.balance-issues-compensation-modal', ['item' => $item])
                    @endif
                </x-admin-dashboard.card>
            @empty
                <x-admin-dashboard.empty-state title="Tidak ada top-up bermasalah yang belum diselesaikan." />
            @endforelse
        </div>

        <div class="hidden overflow-x-auto rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface md:block">
            <table class="min-w-[1180px] text-left text-sm">
                <caption class="sr-only">Queue kompensasi top-up bermasalah</caption>
                <thead class="border-b border-admin-line bg-admin-surface-muted text-xs font-medium text-admin-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Customer</th>
                        <th scope="col" class="px-4 py-3 font-medium">Nominal / saldo</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">Waktu</th>
                        <th scope="col" class="px-4 py-3 font-medium">Provider</th>
                        <th scope="col" class="px-4 py-3 font-medium">Review</th>
                        <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-admin-line">
                    @forelse ($balanceIssuesTab['items'] as $item)
                        <tr x-data="{ releaseOpen: false }">
                            <td class="px-4 py-3 align-top">
                                <p class="font-semibold text-admin-ink">{{ $item['customer_name'] }}</p>
                                <p class="text-xs font-medium text-admin-muted">{{ $item['telegram_id'] }} · {{ $item['username'] }}</p>
                                <p class="mt-1 text-xs font-medium text-admin-muted">Top-up #{{ $item['top_up_id'] }}</p>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <p class="font-semibold text-admin-ink">{{ $item['amount_label'] }}</p>
                                <p class="text-xs font-medium text-admin-muted">Saldo: {{ $item['balance_current_label'] }}</p>
                            </td>
                            <td class="px-4 py-3 align-top"><x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge><p class="mt-1 text-xs font-medium text-admin-muted">{{ $item['provider_create_state'] }}</p></td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-xs font-medium text-admin-ink"><p>{{ $item['created_at_label'] }}</p><p class="text-admin-muted">Umur {{ $item['age_label'] }}</p><p class="text-admin-muted">Provider {{ $item['provider_created_at_label'] }}</p></td>
                            <td class="max-w-[220px] break-all px-4 py-3 align-top font-mono text-xs font-medium text-admin-ink"><p>{{ $item['provider_reference'] }}</p><p class="text-admin-muted">{{ $item['merchant_ref'] }}</p></td>
                            <td class="max-w-[280px] break-words px-4 py-3 align-top text-xs font-medium text-admin-ink">{{ $item['review_reason'] }}</td>
                            <td class="px-4 py-3 align-top">
                                @if ($item['eligible'])
                                    <x-admin-dashboard.button type="button" variant="primary" size="sm" x-on:click="releaseOpen = true">Release saldo</x-admin-dashboard.button>
                                    @include('admin.dashboard.balance-issues-compensation-modal', ['item' => $item])
                                @else
                                    <span class="text-xs font-medium text-admin-muted">Tidak eligible</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-sm font-medium text-admin-muted">Tidak ada top-up bermasalah yang belum diselesaikan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($balanceIssuesTab['pagination']['has_pages'])
            <nav class="rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-3" aria-label="Pagination kompensasi top-up">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-medium text-admin-muted">
                        Halaman {{ $balanceIssuesTab['pagination']['current_page'] }} dari {{ $balanceIssuesTab['pagination']['last_page'] }}
                        <span>· {{ $balanceIssuesTab['pagination']['from'] }}-{{ $balanceIssuesTab['pagination']['to'] }} dari {{ $balanceIssuesTab['pagination']['total'] }} top-up</span>
                    </p>
                    <div class="flex items-center gap-2">
                        @if ($balanceIssuesTab['pagination']['previous_url'])
                            <x-admin-dashboard.button :href="$balanceIssuesTab['pagination']['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Sebelumnya</span>
                        @endif
                        @if ($balanceIssuesTab['pagination']['next_url'])
                            <x-admin-dashboard.button :href="$balanceIssuesTab['pagination']['next_url']" variant="warning" size="sm">Berikutnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface-muted px-3 text-sm font-medium text-admin-muted">Berikutnya</span>
                        @endif
                    </div>
                </div>
            </nav>
        @endif
    @endif
</div>
