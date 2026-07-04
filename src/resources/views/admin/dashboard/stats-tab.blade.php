            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  STATS TAB (Ringkasan)                              ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-show="activeTab === 'stats'" class="mt-4 space-y-4">

                {{-- Link Toko section --}}
                <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="store-link-section overflow-hidden rounded-[1.5rem] text-neo-ink" aria-label="Link toko">
                    <p class="font-display text-xs font-black uppercase tracking-[0.18em] text-neo-ink/80">{{ $storeLink['label'] }}</p>
                    <x-admin-dashboard.card padding="sm" shadow="none" class="mt-2 overflow-hidden rounded-[1rem] px-3 py-2.5">
                        <p class="break-all text-sm font-bold leading-6 text-neo-ink">{{ $storeLink['url'] }}</p>
                    </x-admin-dashboard.card>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <x-admin-dashboard.button
                            type="button"
                            variant="neutral"
                            size="sm"
                            class="text-[11px]"
                            x-on:click="shareStore()"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="2"/></svg>
                            Bagikan
                        </x-admin-dashboard.button>
                        <x-admin-dashboard.button
                            type="button"
                            variant="ink"
                            size="sm"
                            class="text-[11px]"
                            x-on:click="copyStoreLink()"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" stroke-width="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke="currentColor" stroke-width="2"/></svg>
                            <span x-text="copyState">Salin</span>
                        </x-admin-dashboard.button>
                    </div>
                </x-admin-dashboard.card>

                {{-- STATISTIK heading --}}
                <div>
                    <h2 class="font-display text-lg font-black uppercase tracking-[0.18em] text-neo-ink">📊 Ringkasan</h2>
                    <p class="mt-1 text-xs font-semibold text-brand-muted">Gambaran cepat operasional hari ini</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    {{-- Stats cards grid --}}
                    <section class="grid grid-cols-2 gap-3" aria-label="Kartu Stats">
                        @foreach ($statsCards as $metric)
                            <x-admin-dashboard.stat-card
                                :label="$metric['label']"
                                :value="$metric['value']"
                                :hint="$metric['hint']"
                                :tone="$metric['tone']"
                                size="compact"
                                class="rounded-xl border-[2px] shadow-[2px_2px_0_0_#050505]"
                            />
                        @endforeach
                    </section>

                    {{-- STATUS ORDER --}}
                    <section aria-label="Status order">
                        <h2 class="font-display text-sm font-black uppercase tracking-[0.18em] text-neo-ink">📦 Status Pesanan</h2>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            @foreach ($actionableStatusCounts as $status)
                                <x-admin-dashboard.stat-card
                                    :label="$status['label']"
                                    :value="$status['count']"
                                    :tone="$status['tone']"
                                    size="compact"
                                    class="rounded-[1rem] shadow-[3px_3px_0_0_#050505]"
                                />
                            @endforeach
                        </div>
                    </section>
                </div>

                {{-- Pendapatan 7 hari --}}
                <section aria-label="Pendapatan 7 hari">
                    <h2 class="font-display text-sm font-bold uppercase tracking-wider text-neo-ink">💰 Pendapatan 7 hari</h2>
                    <p class="mt-1 text-[11px] font-semibold text-brand-muted">Pembayaran lunas</p>
                    <div class="mt-3 space-y-3">
                        @foreach ($earnings as $earning)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-12 shrink-0 font-display text-xs font-bold text-brand-muted">{{ $earning['label'] }}</span>
                                <div class="earnings-bar-track flex-1">
                                    <div class="earnings-bar-fill" style="width: {{ $earning['bar_percent'] }}%"></div>
                                </div>
                                <span class="w-24 shrink-0 text-right font-display text-xs font-bold text-neo-ink">{{ $earning['amount_formatted'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Payment control (merged from sidebar) --}}
                <x-admin-dashboard.card as="section" tone="purple" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Mode pembayaran">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Mode pembayaran</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">{{ $paymentControl['label'] }}</h2>
                        </div>
                        <x-admin-dashboard.badge
                            :tone="$paymentControl['tone']"
                            dot
                        >
                            {{ $paymentControl['status_badge']['label'] }}
                        </x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-xs font-semibold text-brand-muted">
                        {{ $paymentControl['hint'] }}
                    </p>

                    @if (! $paymentControl['is_pakasir_configured'])
                        <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">
                            {{ $paymentControl['pakasir_guard_message'] }}
                        </x-admin-dashboard.notice>
                    @endif

                    @error('payment_mode', 'paymentMode')
                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    @if ($paymentControl['can_manage'])
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <form method="POST" action="{{ $paymentControl['update_url'] }}">
                                @csrf
                                <input type="hidden" name="payment_mode" value="manual">
                                <x-admin-dashboard.button
                                    type="submit"
                                    :variant="$paymentControl['mode'] === 'manual' ? 'warning' : 'neutral'"
                                    size="sm"
                                    class="w-full text-xs"
                                >
                                    QR Manual /payment
                                </x-admin-dashboard.button>
                            </form>

                            <form method="POST" action="{{ $paymentControl['update_url'] }}">
                                @csrf
                                <input type="hidden" name="payment_mode" value="pakasir">
                                <x-admin-dashboard.button
                                    type="submit"
                                    :variant="$paymentControl['mode'] === 'pakasir' ? 'purple' : 'neutral'"
                                    size="sm"
                                    class="w-full text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="! $paymentControl['can_enable_pakasir']"
                                    title="{{ $paymentControl['pakasir_guard_message'] }}"
                                >
                                    Pakasir {{ $paymentControl['checkout_flow_label'] }}
                                </x-admin-dashboard.button>
                            </form>
                        </div>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            Hanya owner/admin yang bisa mengubah mode pembayaran.
                        </x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>
            </div>
