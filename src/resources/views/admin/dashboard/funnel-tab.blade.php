<div id="admin-section-funnel" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium text-admin-muted">Insight · read-only</p>
            <h2 class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">Storefront Funnel</h2>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-admin-muted">Agregat read-only dari event storefront yang disanitasi dan state Order/Payment canonical. Tidak ada invoice, kontak support, Recovery Key, kredensial, payload provider, atau IP di report.</p>
        </div>
        <p class="text-xs font-medium text-admin-muted">Maksimal 31 hari</p>
    </div>

    @if ($funnelTab['notice'])
        <x-admin-dashboard.notice :tone="$funnelTab['notice']['tone']" role="status">
            <span class="font-semibold">{{ $funnelTab['notice']['title'] }}:</span>
            {{ $funnelTab['notice']['message'] }}
        </x-admin-dashboard.notice>
    @endif

    @if ($funnelTab['can_view'])
        <form method="get" action="{{ route('admin.dashboard') }}" class="rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-4" aria-label="Filter funnel">
            <input type="hidden" name="tab" value="funnel">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-start-date">Mulai</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="funnel-filter-start-date" name="start_date" type="date" value="{{ $funnelTab['filters']['start_date'] }}" class="mt-1" />
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-end-date">Sampai</x-admin-dashboard.field-label>
                    <x-admin-dashboard.input id="funnel-filter-end-date" name="end_date" type="date" value="{{ $funnelTab['filters']['end_date'] }}" class="mt-1" />
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-product">Produk</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-product" name="product_id" class="mt-1">
                        <option value="">Semua produk</option>
                        @foreach ($funnelTab['filter_options']['products'] as $productOption)
                            <option value="{{ $productOption['id'] }}" @selected((string) $funnelTab['filters']['product_id'] === (string) $productOption['id'])>
                                {{ $productOption['name'] }} ({{ $productOption['status_label'] }})
                            </option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-family">Product Family</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-family" name="family_id" class="mt-1">
                        <option value="">Semua family</option>
                        @foreach ($funnelTab['filter_options']['families'] as $familyOption)
                            <option value="{{ $familyOption['id'] }}" @selected((string) $funnelTab['filters']['family_id'] === (string) $familyOption['id'])>
                                {{ $familyOption['name'] }} ({{ $familyOption['status_label'] }})
                            </option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-category">Kategori</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-category" name="category_id" class="mt-1">
                        <option value="">Semua kategori</option>
                        <option value="uncategorized" @selected($funnelTab['filters']['category_id'] === 'uncategorized')>Tanpa kategori</option>
                        @foreach ($funnelTab['filter_options']['categories'] as $categoryOption)
                            <option value="{{ $categoryOption['id'] }}" @selected((string) $funnelTab['filters']['category_id'] === (string) $categoryOption['id'])>
                                {{ $categoryOption['name'] }} ({{ $categoryOption['status_label'] }})
                            </option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-provider">Provider</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-provider" name="provider" class="mt-1">
                        <option value="">Semua provider</option>
                        @foreach ($funnelTab['filter_options']['providers'] as $providerOption)
                            <option value="{{ $providerOption['value'] }}" @selected($funnelTab['filters']['provider'] === $providerOption['value'])>{{ $providerOption['label'] }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-rail">Payment rail</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-rail" name="rail" class="mt-1">
                        <option value="">Semua rail</option>
                        @foreach ($funnelTab['filter_options']['rails'] as $railOption)
                            <option value="{{ $railOption['value'] }}" @selected($funnelTab['filters']['rail'] === $railOption['value'])>{{ $railOption['label'] }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-source">Source</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-source" name="source" class="mt-1">
                        <option value="">Semua source</option>
                        @foreach ($funnelTab['filter_options']['sources'] as $source)
                            <option value="{{ $source }}" @selected($funnelTab['filters']['source'] === $source)>{{ $source }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-utm-source">UTM source</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-utm-source" name="utm_source" class="mt-1">
                        <option value="">Semua UTM source</option>
                        @foreach ($funnelTab['filter_options']['utm_sources'] as $utmSource)
                            <option value="{{ $utmSource }}" @selected($funnelTab['filters']['utm_source'] === $utmSource)>{{ $utmSource }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div>
                    <x-admin-dashboard.field-label for="funnel-filter-utm-medium">UTM medium</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-utm-medium" name="utm_medium" class="mt-1">
                        <option value="">Semua UTM medium</option>
                        @foreach ($funnelTab['filter_options']['utm_mediums'] as $utmMedium)
                            <option value="{{ $utmMedium }}" @selected($funnelTab['filters']['utm_medium'] === $utmMedium)>{{ $utmMedium }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
                <div class="sm:col-span-2 lg:col-span-2">
                    <x-admin-dashboard.field-label for="funnel-filter-utm-campaign">UTM campaign</x-admin-dashboard.field-label>
                    <x-admin-dashboard.select id="funnel-filter-utm-campaign" name="utm_campaign" class="mt-1">
                        <option value="">Semua UTM campaign</option>
                        @foreach ($funnelTab['filter_options']['utm_campaigns'] as $utmCampaign)
                            <option value="{{ $utmCampaign }}" @selected($funnelTab['filters']['utm_campaign'] === $utmCampaign)>{{ $utmCampaign }}</option>
                        @endforeach
                    </x-admin-dashboard.select>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-medium text-admin-muted">Sesi unik memakai HMAC anonim dari session server; reload pada sesi yang sama dihitung satu sesi.</p>
                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full sm:w-auto">Terapkan filter</x-admin-dashboard.button>
            </div>
        </form>

        @if ($funnelTab['state'] === 'ready')
            @php($summary = $funnelTab['summary'])
            <section aria-labelledby="funnel-summary-title" class="space-y-3">
                <div>
                    <h3 id="funnel-summary-title" class="text-lg font-semibold text-admin-ink">Ringkasan periode</h3>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">Paid orders hanya berasal dari Payment berstatus paid dengan paid_at di periode terpilih.</p>
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
                        <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
                            <p class="text-xs font-medium text-admin-muted">{{ $card['label'] }}</p>
                            <p class="mt-1 text-2xl font-semibold tracking-tight text-admin-ink">{{ $card['value'] }}</p>
                            <p class="mt-1 text-xs font-medium text-admin-muted">{{ $card['hint'] }}</p>
                        </x-admin-dashboard.card>
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="funnel-products-title" class="space-y-3">
                <div>
                    <h3 id="funnel-products-title" class="text-lg font-semibold text-admin-ink">Konversi per produk</h3>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">Produk inactive/deleted tetap memakai ID dan label canonical yang tersedia; paid order dihitung satu kali per Order.</p>
                </div>
                <div class="space-y-3 md:hidden">
                    @forelse ($funnelTab['products'] as $productRow)
                        <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-base font-semibold text-admin-ink">{{ $productRow['product_name'] }}</p>
                                    <p class="mt-1 text-xs font-medium text-admin-muted">#{{ $productRow['product_id'] }} · {{ $productRow['category_name'] }}</p>
                                </div>
                                @if ($productRow['is_deleted'])
                                    <x-admin-dashboard.badge tone="rose" dot>Deleted</x-admin-dashboard.badge>
                                @elseif ($productRow['is_active'])
                                    <x-admin-dashboard.badge tone="emerald" dot>Aktif</x-admin-dashboard.badge>
                                @else
                                    <x-admin-dashboard.badge tone="slate" dot>Tidak aktif</x-admin-dashboard.badge>
                                @endif
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-admin-line pt-3 text-sm">
                                <div><dt class="text-xs font-medium text-admin-muted">Views / sesi</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $productRow['views'] }} / {{ $productRow['unique_sessions'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Checkout</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $productRow['checkout_opens'] }} open · {{ $productRow['checkout_submits'] }} submit</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Accepted / rejected</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $productRow['accepted_checkouts'] }} / {{ $productRow['rejected_checkouts'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Paid / conversion</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $productRow['paid_orders'] }} / {{ $productRow['payment_conversion_rate_label'] }}</dd></div>
                            </dl>
                        </x-admin-dashboard.card>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada aktivitas produk pada periode ini." />
                    @endforelse
                </div>
                <div class="hidden overflow-x-auto rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface md:block">
                    <table class="min-w-full text-left text-sm">
                        <caption class="sr-only">Konversi funnel per produk</caption>
                        <thead class="border-b border-admin-line bg-admin-surface-muted text-xs font-medium text-admin-muted">
                            <tr><th scope="col" class="px-4 py-3 font-medium">Produk</th><th scope="col" class="px-4 py-3 font-medium">Views / sesi</th><th scope="col" class="px-4 py-3 font-medium">Checkout</th><th scope="col" class="px-4 py-3 font-medium">Accepted / rejected</th><th scope="col" class="px-4 py-3 font-medium">Paid</th><th scope="col" class="px-4 py-3 font-medium">Conversion</th><th scope="col" class="px-4 py-3 font-medium">AOV</th></tr>
                        </thead>
                        <tbody class="divide-y divide-admin-line">
                            @forelse ($funnelTab['products'] as $productRow)
                                <tr>
                                    <td class="px-4 py-3"><div class="font-semibold text-admin-ink">{{ $productRow['product_name'] }}</div><div class="text-xs font-medium text-admin-muted">#{{ $productRow['product_id'] }} · {{ $productRow['category_name'] }}</div></td>
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $productRow['views'] }} / {{ $productRow['unique_sessions'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $productRow['checkout_opens'] }} / {{ $productRow['checkout_submits'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $productRow['accepted_checkouts'] }} / {{ $productRow['rejected_checkouts'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-admin-ink">{{ $productRow['paid_orders'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-admin-ink">{{ $productRow['payment_conversion_rate_label'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-admin-ink">{{ $productRow['average_order_value_label'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center text-sm font-medium text-admin-muted">Belum ada aktivitas produk pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="funnel-families-title" class="space-y-3">
                <div>
                    <h3 id="funnel-families-title" class="text-lg font-semibold text-admin-ink">Konversi per Product Family</h3>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">Hanya event baru dengan family snapshot yang masuk ke proyeksi ini. History tanpa snapshot tetap unknown-family.</p>
                </div>
                <div class="space-y-3 md:hidden">
                    @forelse ($funnelTab['families'] as $familyRow)
                        <x-admin-dashboard.card as="article" padding="compact" shadow="sm">
                            <p class="text-base font-semibold text-admin-ink">{{ $familyRow['family_name'] }}</p>
                            <p class="mt-1 text-xs font-medium text-admin-muted">Family #{{ $familyRow['family_id'] }} · snapshot</p>
                            <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-admin-line pt-3 text-sm">
                                <div><dt class="text-xs font-medium text-admin-muted">Views</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $familyRow['views'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Checkout</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $familyRow['checkout_opens'] }} / {{ $familyRow['checkout_submits'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Accepted</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $familyRow['accepted_checkouts'] }}</dd></div>
                                <div><dt class="text-xs font-medium text-admin-muted">Paid</dt><dd class="mt-0.5 font-semibold text-admin-ink">{{ $familyRow['paid_orders'] }}</dd></div>
                            </dl>
                        </x-admin-dashboard.card>
                    @empty
                        <x-admin-dashboard.empty-state title="Belum ada family snapshot pada periode ini." />
                    @endforelse
                </div>
                <div class="hidden overflow-x-auto rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface md:block">
                    <table class="min-w-full text-left text-sm">
                        <caption class="sr-only">Konversi funnel per Product Family</caption>
                        <thead class="border-b border-admin-line bg-admin-surface-muted text-xs font-medium text-admin-muted">
                            <tr><th scope="col" class="px-4 py-3 font-medium">Product Family</th><th scope="col" class="px-4 py-3 font-medium">Views</th><th scope="col" class="px-4 py-3 font-medium">Checkout</th><th scope="col" class="px-4 py-3 font-medium">Accepted</th><th scope="col" class="px-4 py-3 font-medium">Paid</th><th scope="col" class="px-4 py-3 font-medium">Conversion</th></tr>
                        </thead>
                        <tbody class="divide-y divide-admin-line">
                            @forelse ($funnelTab['families'] as $familyRow)
                                <tr>
                                    <td class="px-4 py-3"><div class="font-semibold text-admin-ink">{{ $familyRow['family_name'] }}</div><div class="text-xs font-medium text-admin-muted">#{{ $familyRow['family_id'] }} · snapshot</div></td>
                                    <td class="px-4 py-3 font-medium text-admin-ink">{{ $familyRow['views'] }}</td>
                                    <td class="px-4 py-3 font-medium text-admin-ink">{{ $familyRow['checkout_opens'] }} / {{ $familyRow['checkout_submits'] }}</td>
                                    <td class="px-4 py-3 font-medium text-admin-ink">{{ $familyRow['accepted_checkouts'] }}</td>
                                    <td class="px-4 py-3 font-semibold text-admin-ink">{{ $familyRow['paid_orders'] }}</td>
                                    <td class="px-4 py-3 font-semibold text-admin-ink">{{ $familyRow['payment_conversion_rate_label'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-8 text-center text-sm font-medium text-admin-muted">Belum ada family snapshot pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-labelledby="funnel-rails-title">
                    <h3 id="funnel-rails-title" class="text-lg font-semibold text-admin-ink">Payment conversion per rail</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['rails'] as $railRow)
                            <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                <div class="flex items-center justify-between gap-3"><p class="font-semibold text-admin-ink">{{ $railRow['rail_label'] }}</p><x-admin-dashboard.badge tone="slate">{{ $railRow['provider_label'] }}</x-admin-dashboard.badge></div>
                                <p class="mt-1 text-xs font-medium text-admin-muted">{{ $railRow['accepted_checkouts'] }} accepted · {{ $railRow['rejected_checkouts'] }} rejected · {{ $railRow['paid_orders'] }} paid · {{ $railRow['payment_conversion_rate_label'] }}</p>
                                <p class="mt-1 text-xs font-medium text-admin-muted">QR/provider created: {{ $railRow['provider_creation_attempts'] }} · AOV: {{ $railRow['average_order_value_label'] }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-admin-muted">Belum ada data payment rail pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-labelledby="funnel-qris-title">
                    <h3 id="funnel-qris-title" class="text-lg font-semibold text-admin-ink">QR/provider outcomes</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['qris_outcomes'] as $outcome)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3"><span class="text-sm font-medium text-admin-ink">{{ $outcome['provider_label'] }} · {{ $outcome['outcome_label'] }}</span><span class="text-lg font-semibold text-admin-ink">{{ $outcome['count'] }}</span></div>
                        @empty
                            <p class="text-sm text-admin-muted">Belum ada state QR/provider pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-labelledby="funnel-failure-title">
                    <h3 id="funnel-failure-title" class="text-lg font-semibold text-admin-ink">Kategori checkout gagal</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['failure_categories'] as $failure)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3"><span class="text-sm font-medium text-admin-ink">{{ $failure['label'] }}</span><span class="text-lg font-semibold text-admin-ink">{{ $failure['count'] }}</span></div>
                        @empty
                            <p class="text-sm text-admin-muted">Tidak ada kategori kegagalan pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>

                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-labelledby="funnel-support-title">
                    <h3 id="funnel-support-title" class="text-lg font-semibold text-admin-ink">Support demand</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($funnelTab['support_categories'] as $support)
                            <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3"><div class="flex items-center justify-between gap-3"><span class="text-sm font-medium text-admin-ink">{{ $support['label'] }}</span><span class="text-lg font-semibold text-admin-ink">{{ $support['count'] }}</span></div><p class="mt-1 text-xs font-medium text-admin-muted">{{ collect($support['channels'])->map(fn ($channel): string => $channel['channel'].' '.$channel['count'])->implode(' · ') }}</p></div>
                        @empty
                            <p class="text-sm text-admin-muted">Belum ada support selection pada periode ini.</p>
                        @endforelse
                    </div>
                </x-admin-dashboard.card>
            </div>

            <x-admin-dashboard.card as="section" padding="compact" shadow="sm" aria-labelledby="funnel-source-title">
                <h3 id="funnel-source-title" class="text-lg font-semibold text-admin-ink">Acquisition source</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <caption class="sr-only">Acquisition source dan UTM storefront</caption>
                        <thead class="border-b border-admin-line text-xs font-medium text-admin-muted"><tr><th scope="col" class="px-3 py-2 font-medium">Source / UTM</th><th scope="col" class="px-3 py-2 font-medium">Views</th><th scope="col" class="px-3 py-2 font-medium">Checkout</th><th scope="col" class="px-3 py-2 font-medium">Paid</th><th scope="col" class="px-3 py-2 font-medium">Conversion</th></tr></thead>
                        <tbody class="divide-y divide-admin-line">
                            @forelse ($funnelTab['sources'] as $sourceRow)
                                <tr><td class="px-3 py-2"><div class="font-semibold text-admin-ink">{{ $sourceRow['source'] }}</div><div class="text-xs font-medium text-admin-muted">{{ $sourceRow['utm_source'] ?: '-' }} / {{ $sourceRow['utm_medium'] ?: '-' }} / {{ $sourceRow['utm_campaign'] ?: '-' }}</div></td><td class="px-3 py-2 font-medium text-admin-ink">{{ $sourceRow['views'] }}</td><td class="px-3 py-2 font-medium text-admin-ink">{{ $sourceRow['checkout_submits'] }}</td><td class="px-3 py-2 font-semibold text-admin-ink">{{ $sourceRow['paid_orders'] }}</td><td class="px-3 py-2 font-semibold text-admin-ink">{{ $sourceRow['payment_conversion_rate_label'] }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-sm font-medium text-admin-muted">Belum ada source attribution pada periode ini.</td></tr>
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
