<div id="admin-section-balance-issues" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold uppercase tracking-wider text-neo-ink">Kompensasi top-up bermasalah</h2>
            <p class="mt-1 max-w-3xl text-xs font-semibold text-brand-muted">Khusus Owner. Nominal yang dirilis selalu diambil dari record top-up asli; form tidak menerima nominal dari browser.</p>
        </div>
        <x-admin-dashboard.badge tone="danger" dot>Financial mutation</x-admin-dashboard.badge>
    </div>

    @if ($errors->balanceCompensation->any())
        <x-admin-dashboard.notice tone="danger" role="alert">
            {{ $errors->balanceCompensation->first() }}
        </x-admin-dashboard.notice>
    @endif

    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]">
        <form method="GET" action="{{ route('admin.dashboard') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
            <input type="hidden" name="tab" value="balance-issues">
            <label class="block text-xs font-black uppercase tracking-wide text-brand-muted">
                Telegram ID
                <input type="text" name="balance_telegram_id" value="{{ $balanceIssuesTab['filters']['telegram_id'] }}" inputmode="numeric" class="mt-1 w-full rounded-xl border-[3px] border-neo-ink bg-white px-3 py-2 font-bold text-neo-ink" placeholder="123456789">
            </label>
            <label class="block text-xs font-black uppercase tracking-wide text-brand-muted">
                Username
                <input type="text" name="balance_username" value="{{ $balanceIssuesTab['filters']['username'] }}" class="mt-1 w-full rounded-xl border-[3px] border-neo-ink bg-white px-3 py-2 font-bold text-neo-ink" placeholder="username">
            </label>
            <label class="block text-xs font-black uppercase tracking-wide text-brand-muted">
                Status
                <select name="balance_status" class="mt-1 w-full rounded-xl border-[3px] border-neo-ink bg-white px-3 py-2 font-bold text-neo-ink">
                    <option value="">Semua status bermasalah</option>
                    <option value="failed" @selected($balanceIssuesTab['filters']['status'] === 'failed')>Gagal</option>
                    <option value="recovery_pending" @selected($balanceIssuesTab['filters']['status'] === 'recovery_pending')>Recovery pending</option>
                    <option value="invalid_provider_result" @selected($balanceIssuesTab['filters']['status'] === 'invalid_provider_result')>Provider invalid</option>
                    <option value="expired" @selected($balanceIssuesTab['filters']['status'] === 'expired')>Kedaluwarsa</option>
                </select>
            </label>
            <label class="block text-xs font-black uppercase tracking-wide text-brand-muted">
                Umur minimal
                <select name="balance_age" class="mt-1 w-full rounded-xl border-[3px] border-neo-ink bg-white px-3 py-2 font-bold text-neo-ink">
                    <option value="">Semua umur</option>
                    <option value="24" @selected($balanceIssuesTab['filters']['age'] === '24')>24 jam</option>
                    <option value="168" @selected($balanceIssuesTab['filters']['age'] === '168')>7 hari</option>
                    <option value="720" @selected($balanceIssuesTab['filters']['age'] === '720')>30 hari</option>
                </select>
            </label>
            <label class="block text-xs font-black uppercase tracking-wide text-brand-muted xl:col-span-2">
                Referensi provider
                <input type="text" name="balance_provider_reference" value="{{ $balanceIssuesTab['filters']['provider_reference'] }}" class="mt-1 w-full rounded-xl border-[3px] border-neo-ink bg-white px-3 py-2 font-bold text-neo-ink" placeholder="Reference exact">
            </label>
            <div class="flex items-end gap-2 xl:col-span-6">
                <x-admin-dashboard.button type="submit" variant="primary" size="sm">Terapkan filter</x-admin-dashboard.button>
                <x-admin-dashboard.button :href="route('admin.dashboard', ['tab' => 'balance-issues'])" variant="neutral" size="sm">Reset</x-admin-dashboard.button>
            </div>
        </form>
    </x-admin-dashboard.card>

    @if ($balanceIssuesTab['state'] === 'ready')
        <div class="space-y-3 md:hidden">
            @forelse ($balanceIssuesTab['items'] as $item)
                <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="rounded-[1.125rem]" x-data="{ releaseOpen: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[10px] font-black uppercase tracking-wide text-brand-muted">Top-up #{{ $item['top_up_id'] }} · {{ $item['age_label'] }}</p>
                            <p class="mt-1 truncate font-display text-base font-bold text-neo-ink">{{ $item['customer_name'] }}</p>
                            <p class="text-xs font-semibold text-brand-muted">Telegram {{ $item['telegram_id'] }} · {{ $item['username'] }}</p>
                        </div>
                        <x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-3 border-t-2 border-brand-border pt-3 text-sm">
                        <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Nominal asli</dt><dd class="mt-0.5 font-black text-neo-ink">{{ $item['amount_label'] }}</dd></div>
                        <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Saldo saat ini</dt><dd class="mt-0.5 font-black text-neo-ink">{{ $item['balance_current_label'] }}</dd></div>
                        <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Dibuat</dt><dd class="mt-0.5 font-semibold text-neo-ink">{{ $item['created_at_label'] }}</dd></div>
                        <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Provider dibuat</dt><dd class="mt-0.5 font-semibold text-neo-ink">{{ $item['provider_created_at_label'] }}</dd></div>
                        <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Provider ref</dt><dd class="mt-0.5 break-all font-semibold text-neo-ink">{{ $item['provider_reference'] }}</dd></div>
                        <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Review</dt><dd class="mt-0.5 break-words font-semibold text-neo-ink">{{ $item['review_reason'] }}</dd></div>
                    </dl>
                    @if ($item['eligible'])
                        <button type="button" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-neo-yellow px-3 py-2 text-xs font-black uppercase tracking-wide text-neo-ink shadow-neo-sm" x-on:click="releaseOpen = true">Release saldo</button>
                        @include('admin.dashboard.balance-issues-compensation-modal', ['item' => $item])
                    @endif
                </x-admin-dashboard.card>
            @empty
                <x-admin-dashboard.empty-state title="Tidak ada top-up bermasalah yang belum diselesaikan." />
            @endforelse
        </div>

        <div class="hidden overflow-x-auto rounded-[1.125rem] border-[3px] border-neo-ink bg-white shadow-neo-md md:block">
            <table class="min-w-[1180px] text-left text-sm">
                <caption class="sr-only">Queue kompensasi top-up bermasalah</caption>
                <thead class="border-b-[3px] border-neo-ink bg-[#f1f5f9] text-[10px] font-black uppercase tracking-wide text-brand-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3">Customer</th>
                        <th scope="col" class="px-4 py-3">Nominal / saldo</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Waktu</th>
                        <th scope="col" class="px-4 py-3">Provider</th>
                        <th scope="col" class="px-4 py-3">Review</th>
                        <th scope="col" class="px-4 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y-2 divide-brand-border">
                    @forelse ($balanceIssuesTab['items'] as $item)
                        <tr x-data="{ releaseOpen: false }">
                            <td class="px-4 py-3 align-top">
                                <p class="font-black text-neo-ink">{{ $item['customer_name'] }}</p>
                                <p class="text-xs font-semibold text-brand-muted">{{ $item['telegram_id'] }} · {{ $item['username'] }}</p>
                                <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-brand-muted">Top-up #{{ $item['top_up_id'] }}</p>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <p class="font-black text-neo-ink">{{ $item['amount_label'] }}</p>
                                <p class="text-xs font-semibold text-brand-muted">Saldo: {{ $item['balance_current_label'] }}</p>
                            </td>
                            <td class="px-4 py-3 align-top"><x-admin-dashboard.badge :tone="$item['status_tone']" dot>{{ $item['status_label'] }}</x-admin-dashboard.badge><p class="mt-1 text-xs font-semibold text-brand-muted">{{ $item['provider_create_state'] }}</p></td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-xs font-semibold text-neo-ink"><p>{{ $item['created_at_label'] }}</p><p class="text-brand-muted">Umur {{ $item['age_label'] }}</p><p class="text-brand-muted">Provider {{ $item['provider_created_at_label'] }}</p></td>
                            <td class="max-w-[220px] break-all px-4 py-3 align-top text-xs font-semibold text-neo-ink"><p>{{ $item['provider_reference'] }}</p><p class="text-brand-muted">{{ $item['merchant_ref'] }}</p></td>
                            <td class="max-w-[280px] break-words px-4 py-3 align-top text-xs font-semibold text-neo-ink">{{ $item['review_reason'] }}</td>
                            <td class="px-4 py-3 align-top">
                                @if ($item['eligible'])
                                    <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-neo-yellow px-3 py-2 text-xs font-black uppercase tracking-wide text-neo-ink shadow-neo-sm" x-on:click="releaseOpen = true">Release saldo</button>
                                    @include('admin.dashboard.balance-issues-compensation-modal', ['item' => $item])
                                @else
                                    <span class="text-xs font-bold text-brand-muted">Tidak eligible</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center font-bold text-brand-muted">Tidak ada top-up bermasalah yang belum diselesaikan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($balanceIssuesTab['pagination']['has_pages'])
            <nav class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white p-3 shadow-neo-sm" aria-label="Pagination kompensasi top-up">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-black uppercase tracking-wide text-brand-muted">
                        Halaman {{ $balanceIssuesTab['pagination']['current_page'] }} dari {{ $balanceIssuesTab['pagination']['last_page'] }}
                        <span class="normal-case tracking-normal">· {{ $balanceIssuesTab['pagination']['from'] }}-{{ $balanceIssuesTab['pagination']['to'] }} dari {{ $balanceIssuesTab['pagination']['total'] }} top-up</span>
                    </p>
                    <div class="flex items-center gap-2">
                        @if ($balanceIssuesTab['pagination']['previous_url'])
                            <x-admin-dashboard.button :href="$balanceIssuesTab['pagination']['previous_url']" variant="neutral" size="sm">Sebelumnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Sebelumnya</span>
                        @endif
                        @if ($balanceIssuesTab['pagination']['next_url'])
                            <x-admin-dashboard.button :href="$balanceIssuesTab['pagination']['next_url']" variant="warning" size="sm">Berikutnya</x-admin-dashboard.button>
                        @else
                            <span class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-[#f1f5f9] px-3 py-2 text-xs font-black uppercase tracking-wide text-brand-muted opacity-60">Berikutnya</span>
                        @endif
                    </div>
                </div>
            </nav>
        @endif
    @endif
</div>
