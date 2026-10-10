<div id="admin-section-funnel" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold uppercase tracking-wider text-neo-ink">Storefront Funnel</h2>
            <p class="mt-1 max-w-3xl text-xs font-semibold text-brand-muted">Agregat read-only dari event storefront yang disanitasi dan state Order/Payment canonical. Tidak ada invoice, kontak support, Recovery Key, kredensial, payload provider, atau IP di report.</p>
        </div>
        <p class="text-xs font-black uppercase tracking-wide text-brand-muted">Maksimal 31 hari</p>
    </div>

    @if ($funnelTab['notice'])
        <x-admin-dashboard.notice :tone="$funnelTab['notice']['tone']" role="status">
            <span class="font-black">{{ $funnelTab['notice']['title'] }}:</span>
            {{ $funnelTab['notice']['message'] }}
        </x-admin-dashboard.notice>
    @endif

    @if ($funnelTab['can_view'])
        <form method="get" action="{{ route('admin.dashboard') }}" class="rounded-[1.125rem] border-[3px] border-neo-ink bg-white p-4 shadow-neo-sm">
            <input type="hidden" name="tab" value="funnel">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Mulai</span>
                    <input type="date" name="start_date" value="{{ $funnelTab['filters']['start_date'] }}" class="admin-dashboard-input w-full">
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Sampai</span>
                    <input type="date" name="end_date" value="{{ $funnelTab['filters']['end_date'] }}" class="admin-dashboard-input w-full">
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Produk</span>
                    <select name="product_id" class="admin-dashboard-input w-full">
                        <option value="">Semua produk</option>
                        @foreach ($funnelTab['filter_options']['products'] as $productOption)
                            <option value="{{ $productOption['id'] }}" @selected((string) $funnelTab['filters']['product_id'] === (string) $productOption['id'])>
                                {{ $productOption['name'] }} ({{ $productOption['status_label'] }})
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Product Family</span>
                    <select name="family_id" class="admin-dashboard-input w-full">
                        <option value="">Semua family</option>
                        @foreach ($funnelTab['filter_options']['families'] as $familyOption)
                            <option value="{{ $familyOption['id'] }}" @selected((string) $funnelTab['filters']['family_id'] === (string) $familyOption['id'])>
                                {{ $familyOption['name'] }} ({{ $familyOption['status_label'] }})
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Kategori</span>
                    <select name="category_id" class="admin-dashboard-input w-full">
                        <option value="">Semua kategori</option>
                        <option value="uncategorized" @selected($funnelTab['filters']['category_id'] === 'uncategorized')>Tanpa kategori</option>
                        @foreach ($funnelTab['filter_options']['categories'] as $categoryOption)
                            <option value="{{ $categoryOption['id'] }}" @selected((string) $funnelTab['filters']['category_id'] === (string) $categoryOption['id'])>
                                {{ $categoryOption['name'] }} ({{ $categoryOption['status_label'] }})
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Provider</span>
                    <select name="provider" class="admin-dashboard-input w-full">
                        <option value="">Semua provider</option>
                        @foreach ($funnelTab['filter_options']['providers'] as $providerOption)
                            <option value="{{ $providerOption['value'] }}" @selected($funnelTab['filters']['provider'] === $providerOption['value'])>{{ $providerOption['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Payment rail</span>
                    <select name="rail" class="admin-dashboard-input w-full">
                        <option value="">Semua rail</option>
                        @foreach ($funnelTab['filter_options']['rails'] as $railOption)
                            <option value="{{ $railOption['value'] }}" @selected($funnelTab['filters']['rail'] === $railOption['value'])>{{ $railOption['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">Source</span>
                    <select name="source" class="admin-dashboard-input w-full">
                        <option value="">Semua source</option>
                        @foreach ($funnelTab['filter_options']['sources'] as $source)
                            <option value="{{ $source }}" @selected($funnelTab['filters']['source'] === $source)>{{ $source }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">UTM source</span>
                    <select name="utm_source" class="admin-dashboard-input w-full">
                        <option value="">Semua UTM source</option>
                        @foreach ($funnelTab['filter_options']['utm_sources'] as $utmSource)
                            <option value="{{ $utmSource }}" @selected($funnelTab['filters']['utm_source'] === $utmSource)>{{ $utmSource }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">UTM medium</span>
                    <select name="utm_medium" class="admin-dashboard-input w-full">
                        <option value="">Semua UTM medium</option>
                        @foreach ($funnelTab['filter_options']['utm_mediums'] as $utmMedium)
                            <option value="{{ $utmMedium }}" @selected($funnelTab['filters']['utm_medium'] === $utmMedium)>{{ $utmMedium }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block sm:col-span-2 lg:col-span-2">
                    <span class="mb-1 block text-[10px] font-black uppercase tracking-wide text-brand-muted">UTM campaign</span>
                    <select name="utm_campaign" class="admin-dashboard-input w-full">
                        <option value="">Semua UTM campaign</option>
                        @foreach ($funnelTab['filter_options']['utm_campaigns'] as $utmCampaign)
                            <option value="{{ $utmCampaign }}" @selected($funnelTab['filters']['utm_campaign'] === $utmCampaign)>{{ $utmCampaign }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold text-brand-muted">Sesi unik memakai HMAC anonim dari session server; reload pada sesi yang sama dihitung satu sesi.</p>
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-neo-2xl border-[3px] border-neo-ink bg-neo-yellow px-4 py-2 text-xs font-black uppercase tracking-wide text-neo-ink shadow-neo-sm">Terapkan filter</button>
            </div>
        </form>

        @if ($funnelTab['state'] === 'ready')
            @php($summary = $funnelTab['summary'])
            <section aria-labelledby="funnel-summary-title" class="space-y-3">
                <div>
                    <h3 id="funnel-summary-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Ringkasan periode</h3>
                    <p class="mt-1 text-xs font-semibold text-brand-muted">Paid orders hanya berasal dari Payment berstatus paid dengan paid_at di periode terpilih.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['label' => 'Product views', 'value' => $summary['views'], 'hint' => $summary['unique_sessions'].' sesi unik'],
                        ['label' => 'Checkout opens', 'value' => $summary['checkout_opens'], 'hint' => $summary['checkout_submits'].' submit'],
                        ['label' => 'Accepted / rejected', 'value' => $summary['accepted_checkouts'].' / '.$summary['rejected_checkouts'], 'hint' => $summary['other_checkout_outcomes'].' outcome lain'],
                        ['label' => 'Paid orders', 'value' => $summary['paid_orders'], 'hint' => $summary['payment_conversion_rate_label'].' conversion'],
                        ['label' => 'QR/provider created', 'value' => $summary['qris_created'], 'hint' => $summary['provider_creation_attempts'].' attempt tercatat'],
                        ['label' => 'Average order value', 'value' => $summary['average_order_value_label'], 'hint' => 'IDR order total'],
                        ['label' => 'Support selections', 'value' => $summary['support_selections'], 'hint' => 'kategori bantuan'],
                        ['label' => 'Repeat-order signal', 'value' => $summary['repeat_order_signal']['repeat_customers'], 'hint' => $summary['repeat_order_signal']['rate_label'].' dari '.$summary['repeat_order_signal']['paid_customers'].' customer paid'],
                    ] as $card)
                        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="rounded-[1.125rem]">
                            <p class="text-[10px] font-black uppercase tracking-wide text-brand-muted">{{ $card['label'] }}</p>
                            <p class="mt-1 font-display text-2xl font-black text-neo-ink">{{ $card['value'] }}</p>
                            <p class="mt-1 text-xs font-semibold text-brand-muted">{{ $card['hint'] }}</p>
                        </x-admin-dashboard.card>
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="funnel-products-title" class="space-y-3">
                <div>
                    <h3 id="funnel-products-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Konversi per produk</h3>
                    <p class="mt-1 text-xs font-semibold text-brand-muted">Produk inactive/deleted tetap memakai ID dan label canonical yang tersedia; paid order dihitung satu kali per Order.</p>
                </div>
                <div class="space-y-3 md:hidden">
                    @forelse ($funnelTab['products'] as $productRow)
                        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="rounded-[1.125rem]">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-display text-base font-bold text-neo-ink">{{ $productRow['product_name'] }}</p>
                                    <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-brand-muted">#{{ $productRow['product_id'] }} · {{ $productRow['category_name'] }}</p>
                                </div>
                                @if ($productRow['is_deleted'])
                                    <x-admin-dashboard.badge tone="rose" dot>Deleted</x-admin-dashboard.badge>
                                @elseif ($productRow['is_active'])
                                    <x-admin-dashboard.badge tone="emerald" dot>Aktif</x-admin-dashboard.badge>
                                @else
                                    <x-admin-dashboard.badge tone="slate" dot>Tidak aktif</x-admin-dashboard.badge>
                                @endif
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-3 border-t-2 border-brand-border pt-3 text-sm">
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Views / sesi</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $productRow['views'] }} / {{ $productRow['unique_sessions'] }}</dd></div>
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Checkout</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $productRow['checkout_opens'] }} open · {{ $productRow['checkout_submits'] }} submit</dd></div>
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Accepted / rejected</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $productRow['accepted_checkouts'] }} / {{ $productRow['rejected_checkouts'] }}</dd></div>
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Paid / conversion</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $productRow['paid_orders'] }} / {{ $productRow['payment_conversion_rate_label'] }}</dd></div>
                            </dl>
                        </x-admin-dashboard.card>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada aktivitas produk pada periode ini." />
                    @endforelse
                </div>
                <div class="hidden overflow-x-auto rounded-[1.125rem] border-[3px] border-neo-ink bg-white shadow-neo-md md:block">
                    <table class="min-w-full text-left text-sm">
                        <caption class="sr-only">Konversi funnel per produk</caption>
                        <thead class="border-b-[3px] border-neo-ink bg-[#f1f5f9] text-[10px] font-black uppercase tracking-wide text-brand-muted">
                            <tr><th scope="col" class="px-4 py-3">Produk</th><th scope="col" class="px-4 py-3">Views / sesi</th><th scope="col" class="px-4 py-3">Checkout</th><th scope="col" class="px-4 py-3">Accepted / rejected</th><th scope="col" class="px-4 py-3">Paid</th><th scope="col" class="px-4 py-3">Conversion</th><th scope="col" class="px-4 py-3">AOV</th></tr>
                        </thead>
                        <tbody class="divide-y-2 divide-brand-border">
                            @forelse ($funnelTab['products'] as $productRow)
                                <tr>
                                    <td class="px-4 py-3"><div class="font-black text-neo-ink">{{ $productRow['product_name'] }}</div><div class="text-xs font-semibold text-brand-muted">#{{ $productRow['product_id'] }} · {{ $productRow['category_name'] }}</div></td>
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $productRow['views'] }} / {{ $productRow['unique_sessions'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $productRow['checkout_opens'] }} / {{ $productRow['checkout_submits'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $productRow['accepted_checkouts'] }} / {{ $productRow['rejected_checkouts'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-black text-neo-ink">{{ $productRow['paid_orders'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-black text-neo-ink">{{ $productRow['payment_conversion_rate_label'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-neo-ink">{{ $productRow['average_order_value_label'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center font-bold text-brand-muted">Belum ada aktivitas produk pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="funnel-families-title" class="space-y-3">
                <div>
                    <h3 id="funnel-families-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Konversi per Product Family</h3>
                    <p class="mt-1 text-xs font-semibold text-brand-muted">Hanya event baru dengan family snapshot yang masuk ke proyeksi ini. History tanpa snapshot tetap unknown-family.</p>
                </div>
                <div class="space-y-3 md:hidden">
                    @forelse ($funnelTab['families'] as $familyRow)
                        <x-admin-dashboard.card as="article" padding="compact" shadow="sm" class="rounded-[1.125rem]">
                            <p class="font-display text-base font-bold text-neo-ink">{{ $familyRow['family_name'] }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-brand-muted">Family #{{ $familyRow['family_id'] }} · snapshot</p>
                            <dl class="mt-3 grid grid-cols-2 gap-3 border-t-2 border-brand-border pt-3 text-sm">
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Views</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $familyRow['views'] }}</dd></div>
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Checkout</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $familyRow['checkout_opens'] }} / {{ $familyRow['checkout_submits'] }}</dd></div>
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Accepted</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $familyRow['accepted_checkouts'] }}</dd></div>
                                <div><dt class="text-[9px] font-bold uppercase text-brand-muted">Paid</dt><dd class="mt-0.5 font-bold text-neo-ink">{{ $familyRow['paid_orders'] }}</dd></div>
                            </dl>
                        </x-admin-dashboard.card>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada family snapshot pada periode ini." />
                    @endforelse
                </div>
                <div class="hidden overflow-x-auto rounded-[1.125rem] border-[3px] border-neo-ink bg-white shadow-neo-md md:block">
                    <table class="min-w-full text-left text-sm">
                        <caption class="sr-only">Konversi funnel per Product Family</caption>
                        <thead class="border-b-[3px] border-neo-ink bg-[#f1f5f9] text-[10px] font-black uppercase tracking-wide text-brand-muted">
                            <tr><th scope="col" class="px-4 py-3">Product Family</th><th scope="col" class="px-4 py-3">Views</th><th scope="col" class="px-4 py-3">Checkout</th><th scope="col" class="px-4 py-3">Accepted</th><th scope="col" class="px-4 py-3">Paid</th><th scope="col" class="px-4 py-3">Conversion</th></tr>
                        </thead>
                        <tbody class="divide-y-2 divide-brand-border">
                            @forelse ($funnelTab['families'] as $familyRow)
                                <tr>
                                    <td class="px-4 py-3"><div class="font-black text-neo-ink">{{ $familyRow['family_name'] }}</div><div class="text-xs font-semibold text-brand-muted">#{{ $familyRow['family_id'] }} · snapshot</div></td>
                                    <td class="px-4 py-3 font-semibold text-neo-ink">{{ $familyRow['views'] }}</td>
                                    <td class="px-4 py-3 font-semibold text-neo-ink">{{ $familyRow['checkout_opens'] }} / {{ $familyRow['checkout_submits'] }}</td>
                                    <td class="px-4 py-3 font-semibold text-neo-ink">{{ $familyRow['accepted_checkouts'] }}</td>
                                    <td class="px-4 py-3 font-black text-neo-ink">{{ $familyRow['paid_orders'] }}</td>
                                    <td class="px-4 py-3 font-black text-neo-ink">{{ $familyRow['payment_conversion_rate_label'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-8 text-center font-bold text-brand-muted">Belum ada family snapshot pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-labelledby="funnel-rails-title">
                    <h3 id="funnel-rails-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Payment conversion per rail</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['rails'] as $railRow)
                            <div class="rounded-xl border-2 border-brand-border bg-white p-3">
                                <div class="flex items-center justify-between gap-3"><p class="font-black text-neo-ink">{{ $railRow['rail_label'] }}</p><x-admin-dashboard.badge tone="slate">{{ $railRow['provider_label'] }}</x-admin-dashboard.badge></div>
                                <p class="mt-1 text-xs font-semibold text-brand-muted">{{ $railRow['accepted_checkouts'] }} accepted · {{ $railRow['rejected_checkouts'] }} rejected · {{ $railRow['paid_orders'] }} paid · {{ $railRow['payment_conversion_rate_label'] }}</p>
                                <p class="mt-1 text-xs font-semibold text-brand-muted">QR/provider created: {{ $railRow['provider_creation_attempts'] }} · AOV: {{ $railRow['average_order_value_label'] }}</p>
                            </div>
                        @empty
                            <p class="text-sm font-semibold text-brand-muted">Belum ada data payment rail pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-labelledby="funnel-qris-title">
                    <h3 id="funnel-qris-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">QR/provider outcomes</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['qris_outcomes'] as $outcome)
                            <div class="flex items-center justify-between gap-3 rounded-xl border-2 border-brand-border bg-white p-3"><span class="text-sm font-bold text-neo-ink">{{ $outcome['provider_label'] }} · {{ $outcome['outcome_label'] }}</span><span class="font-display text-lg font-black text-neo-ink">{{ $outcome['count'] }}</span></div>
                        @empty
                            <p class="text-sm font-semibold text-brand-muted">Belum ada state QR/provider pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-labelledby="funnel-failure-title">
                    <h3 id="funnel-failure-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Kategori checkout gagal</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['failure_categories'] as $failure)
                            <div class="flex items-center justify-between gap-3 rounded-xl border-2 border-brand-border bg-white p-3"><span class="text-sm font-bold text-neo-ink">{{ $failure['label'] }}</span><span class="font-display text-lg font-black text-neo-ink">{{ $failure['count'] }}</span></div>
                        @empty
                            <p class="text-sm font-semibold text-brand-muted">Tidak ada kategori kegagalan pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-labelledby="funnel-support-title">
                    <h3 id="funnel-support-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Support demand</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['support_categories'] as $support)
                            <div class="rounded-xl border-2 border-brand-border bg-white p-3"><div class="flex items-center justify-between gap-3"><span class="text-sm font-bold text-neo-ink">{{ $support['label'] }}</span><span class="font-display text-lg font-black text-neo-ink">{{ $support['count'] }}</span></div><p class="mt-1 text-xs font-semibold text-brand-muted">{{ collect($support['channels'])->map(fn ($channel): string => $channel['channel'].' '.$channel['count'])->implode(' · ') }}</p></div>
                        @empty
                            <p class="text-sm font-semibold text-brand-muted">Belum ada support selection pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>
            </div>

            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-labelledby="funnel-source-title">
                <h3 id="funnel-source-title" class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Acquisition source</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <caption class="sr-only">Acquisition source dan UTM storefront</caption>
                        <thead class="border-b-2 border-brand-border text-[10px] font-black uppercase tracking-wide text-brand-muted"><tr><th scope="col" class="px-3 py-2">Source / UTM</th><th scope="col" class="px-3 py-2">Views</th><th scope="col" class="px-3 py-2">Checkout</th><th scope="col" class="px-3 py-2">Paid</th><th scope="col" class="px-3 py-2">Conversion</th></tr></thead>
                        <tbody class="divide-y-2 divide-brand-border">
                            @forelse ($funnelTab['sources'] as $sourceRow)
                                <tr><td class="px-3 py-2"><div class="font-black text-neo-ink">{{ $sourceRow['source'] }}</div><div class="text-xs font-semibold text-brand-muted">{{ $sourceRow['utm_source'] ?: '-' }} / {{ $sourceRow['utm_medium'] ?: '-' }} / {{ $sourceRow['utm_campaign'] ?: '-' }}</div></td><td class="px-3 py-2 font-semibold text-neo-ink">{{ $sourceRow['views'] }}</td><td class="px-3 py-2 font-semibold text-neo-ink">{{ $sourceRow['checkout_submits'] }}</td><td class="px-3 py-2 font-black text-neo-ink">{{ $sourceRow['paid_orders'] }}</td><td class="px-3 py-2 font-black text-neo-ink">{{ $sourceRow['payment_conversion_rate_label'] }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center font-bold text-brand-muted">Belum ada source attribution pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-admin-dashboard.card>
        @elseif ($funnelTab['state'] === 'error')
            <x-admin-dashboard.empty-state title="Periksa filter tanggal atau pilihan filter lalu coba lagi." />
        @endif
    @endif
</div>
