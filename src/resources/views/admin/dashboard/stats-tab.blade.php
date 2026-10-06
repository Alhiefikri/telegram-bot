            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  STATS TAB (Ringkasan)                              ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            @php($bsc = $paymentControl['bsc'] ?? [])
            <div id="admin-section-stats" data-admin-return-target tabindex="-1" x-show="activeTab === 'stats'" class="mt-4 space-y-4">

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

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {{-- Stats cards grid --}}
                    <section class="grid min-w-0 grid-cols-2 gap-3 lg:grid-cols-3 xl:col-span-2 xl:grid-cols-5" aria-label="Kartu Stats">
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
                    <section class="min-w-0" aria-label="Status order">
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
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
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
                <x-admin-dashboard.card id="admin-payment-settings" data-admin-return-target tabindex="-1" as="section" tone="purple" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Mode pembayaran">
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


                    @error('payment_mode', 'paymentMode')
                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    <div class="mt-3 rounded-xl border-2 border-neo-ink/15 bg-white/70 p-3">
                        <p class="text-[11px] font-black uppercase tracking-wider text-brand-muted">Provider requested / effective</p>
                        <p class="mt-1 text-sm font-black text-neo-ink">
                            {{ $paymentControl['requested_provider_label'] }}
                            <span class="font-semibold text-brand-muted">· efektif {{ $paymentControl['effective_provider_label'] }}</span>
                        </p>
                        <p class="mt-1 text-[11px] font-semibold text-brand-muted">{{ $paymentControl['hint'] }}</p>
                    </div>

                    <div class="mt-3 grid gap-2 sm:grid-cols-3" aria-label="Readiness dan antrean provider">
                        @foreach ($paymentControl['providers'] as $provider => $providerStatus)
                            <div class="rounded-xl border-2 border-neo-ink/15 bg-white p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-black text-neo-ink">{{ $providerStatus['label'] }}</span>
                                    <x-admin-dashboard.badge :tone="$providerStatus['ready'] ? 'emerald' : 'amber'">
                                        {{ $providerStatus['ready'] ? 'Siap' : 'Belum siap' }}
                                    </x-admin-dashboard.badge>
                                </div>
                                <p class="mt-2 text-[11px] font-semibold text-brand-muted">
                                    Pending: {{ $paymentControl['pending_by_provider'][$provider] ?? 0 }}
                                    @if ($paymentControl['oldest_pending_by_provider'][$provider] ?? null)
                                        · tertua {{ $paymentControl['oldest_pending_by_provider'][$provider] }}
                                    @endif
                                    @if ($provider === 'sakurupiah' && ($paymentControl['sakurupiah_backlog']['verification_pending'] ?? 0) > 0)
                                        · <span class="font-black text-amber-700">verifikasi {{ $paymentControl['sakurupiah_backlog']['verification_pending'] }}</span>
                                    @endif
                                </p>
                                @if (! $providerStatus['ready'])
                                    <p class="mt-1 text-[10px] font-semibold text-brand-muted">{{ $providerStatus['reason'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>


                    @if ($paymentControl['switch_audit'] !== [])
                        <div class="mt-3 rounded-xl border-2 border-neo-ink/15 bg-white/70 p-3">
                            <p class="text-[11px] font-black uppercase tracking-wider text-brand-muted">Audit provider terakhir</p>
                            <div class="mt-2 space-y-1 text-[11px] font-semibold text-brand-muted">
                                @foreach ($paymentControl['switch_audit'] as $audit)
                                    <p>{{ $audit['old_provider'] }} → {{ $audit['new_provider'] }} · {{ $audit['actor'] }} · {{ $audit['at'] }}</p>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @error('confirm_provider_switch', 'paymentMode')
                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    @if ($paymentControl['can_switch_provider'])
                        <p class="mt-3 text-[11px] font-semibold text-brand-muted">
                            Setiap perubahan meminta konfirmasi provider lama → baru dan hanya memengaruhi checkout baru.
                        </p>
                        <div class="mt-3 grid gap-2 md:grid-cols-3">
                            <form method="POST" action="{{ $paymentControl['update_url'] }}" data-provider-switch-from="{{ $paymentControl['requested_provider_label'] }}" data-provider-switch-target="Manual" x-on:submit.prevent="openPaymentProviderSwitch($event)">
                                @csrf
                                <input type="hidden" name="update_scope" value="provider_switch">
                                <input type="hidden" name="payment_mode" value="manual">
                                <input type="hidden" name="payment_provider_version" value="{{ $paymentControl['payment_provider_version'] }}">
                                <input type="hidden" name="confirm_provider_switch" value="1">
                                <x-admin-dashboard.button type="submit" :variant="$paymentControl['requested_mode'] === 'manual' ? 'warning' : 'neutral'" size="sm" class="w-full text-[11px]">
                                    QR Manual
                                </x-admin-dashboard.button>
                            </form>
                            <form method="POST" action="{{ $paymentControl['update_url'] }}" data-provider-switch-from="{{ $paymentControl['requested_provider_label'] }}" data-provider-switch-target="KlikQRIS" x-on:submit.prevent="openPaymentProviderSwitch($event)">
                                @csrf
                                <input type="hidden" name="update_scope" value="provider_switch">
                                <input type="hidden" name="payment_mode" value="klikqris">
                                <input type="hidden" name="payment_provider_version" value="{{ $paymentControl['payment_provider_version'] }}">
                                <input type="hidden" name="confirm_provider_switch" value="1">
                                <x-admin-dashboard.button type="submit" :variant="$paymentControl['requested_mode'] === 'klikqris' ? 'purple' : 'neutral'" size="sm" class="w-full text-[11px] disabled:cursor-not-allowed disabled:opacity-60" :disabled="! $paymentControl['can_enable_klikqris']" title="{{ $paymentControl['klikqris_guard_message'] }}">
                                    KlikQRIS
                                </x-admin-dashboard.button>
                            </form>
                            <form method="POST" action="{{ $paymentControl['update_url'] }}" data-provider-switch-from="{{ $paymentControl['requested_provider_label'] }}" data-provider-switch-target="Sakurupiah" x-on:submit.prevent="openPaymentProviderSwitch($event)">
                                @csrf
                                <input type="hidden" name="update_scope" value="provider_switch">
                                <input type="hidden" name="payment_mode" value="sakurupiah">
                                <input type="hidden" name="payment_provider_version" value="{{ $paymentControl['payment_provider_version'] }}">
                                <input type="hidden" name="confirm_provider_switch" value="1">
                                <x-admin-dashboard.button type="submit" :variant="$paymentControl['requested_mode'] === 'sakurupiah' ? 'purple' : 'neutral'" size="sm" class="w-full text-[11px] disabled:cursor-not-allowed disabled:opacity-60" :disabled="! $paymentControl['can_enable_sakurupiah']" title="{{ $paymentControl['sakurupiah_guard_message'] }}">
                                    Sakurupiah
                                </x-admin-dashboard.button>
                            </form>
                        </div>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            {{ $paymentControl['provider_switch_guard_message'] }}
                        </x-admin-dashboard.notice>
                    @endif

                    @if ($paymentControl['can_switch_provider'])
                        <div
                            x-cloak
                            x-show="paymentSwitchOpen"
                            x-transition.opacity
                            class="fixed inset-0 z-[70] flex items-center justify-center bg-neo-ink/60 p-3 sm:p-4"
                            x-on:keydown.escape.window="if (paymentSwitchOpen) closePaymentProviderSwitch()"
                        >
                            <div class="absolute inset-0" x-on:click="closePaymentProviderSwitch()" aria-hidden="true"></div>
                            <div
                                id="admin-payment-provider-switch-dialog"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="admin-payment-provider-switch-title"
                                aria-describedby="admin-payment-provider-switch-description"
                                tabindex="-1"
                                x-on:keydown.tab="trapModalFocus($event)"
                                x-on:focusin.window="if (paymentSwitchOpen) keepModalFocus($event, 'admin-payment-provider-switch-dialog')"
                                class="relative max-h-[calc(100dvh-1.5rem)] w-full max-w-lg overflow-y-auto rounded-[1.25rem] border-[4px] border-neo-ink bg-neo-paper p-4 text-neo-ink shadow-neo-xl sm:p-5"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 pr-8">
                                        <p class="font-display text-[10px] font-black uppercase tracking-[0.18em] text-brand-muted">Payment Provider Switch</p>
                                        <h2 id="admin-payment-provider-switch-title" class="mt-1 font-display text-xl font-black uppercase leading-tight">Konfirmasi ganti provider pembayaran</h2>
                                    </div>
                                    <button
                                        type="button"
                                        class="absolute right-3 top-3 inline-flex h-10 w-10 items-center justify-center rounded-[0.45rem] border-[3px] border-neo-ink bg-white text-lg font-black shadow-neo-sm"
                                        aria-label="Tutup dialog provider"
                                        x-on:click="closePaymentProviderSwitch()"
                                    >×</button>
                                </div>

                                <div id="admin-payment-provider-switch-description" class="mt-4 space-y-3 text-sm font-bold leading-6">
                                    <p>
                                        Ubah provider dari
                                        <span class="font-black" x-text="paymentSwitchCurrentLabel"></span>
                                        ke
                                        <span class="font-black" x-text="paymentSwitchTargetLabel"></span>?
                                    </p>
                                    <p class="rounded-xl border-2 border-neo-ink/20 bg-white/80 p-3 text-xs font-semibold leading-5">
                                        Hanya checkout baru yang berubah. Payment pending/lama tetap diproses dengan provider asalnya.
                                    </p>
                                </div>

                                <div class="mt-4 grid grid-cols-2 gap-2">
                                    <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closePaymentProviderSwitch()">
                                        Batal
                                    </x-admin-dashboard.button>
                                    <x-admin-dashboard.button type="button" variant="primary" x-ref="paymentSwitchConfirm" x-on:click="submitPaymentProviderSwitch()">
                                        Konfirmasi switch
                                    </x-admin-dashboard.button>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($paymentControl['can_manage'])
                        <form id="admin-binance-enabled-setting" method="POST" action="{{ $paymentControl['update_url'] }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="update_scope" value="binance_settings">
                            <input type="hidden" name="payment_mode" value="{{ $paymentControl['requested_mode'] }}">
                            <input type="hidden" name="binance_pay_enabled" value="{{ $paymentControl['binance_pay_enabled'] ? 0 : 1 }}">
                            <x-admin-dashboard.button type="submit" :variant="$paymentControl['binance_pay_enabled'] ? 'primary' : 'neutral'" size="sm" class="w-full text-[11px]">
                                {{ $paymentControl['binance_pay_enabled'] ? 'Binance Pay USDT: Aktif' : 'Aktifkan Binance Pay USDT' }}
                            </x-admin-dashboard.button>
                        </form>
                        <div class="mt-4 border-t-2 border-neo-ink/15 pt-4">
                            <h3 class="font-display text-sm font-black uppercase tracking-wider text-neo-ink">Verifikasi Binance</h3>
                            <p class="mt-1 text-[11px] font-semibold text-brand-muted">Pilih Order ID setelah API key, secret, dan Binance Pay ID atau UID dikonfigurasi. Amount unik tetap memakai nominal berurutan.</p>
                            @error('binance_verification_mode', 'paymentMode')
                                <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                            @enderror
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach (['amount_unique' => 'Amount unik (legacy)', 'order_id' => 'Order ID'] as $binanceMode => $binanceModeLabel)
                                    <form method="POST" action="{{ $paymentControl['update_url'] }}">
                                        @csrf
                                        <input type="hidden" name="update_scope" value="binance_verification">
                                        <input type="hidden" name="payment_mode" value="{{ $paymentControl['requested_mode'] }}">
                                        <input type="hidden" name="binance_verification_mode" value="{{ $binanceMode }}">
                                        <x-admin-dashboard.button
                                            type="submit"
                                            :variant="$paymentControl['binance_verification_mode'] === $binanceMode ? 'primary' : 'neutral'"
                                            size="sm"
                                            class="w-full text-[11px]"
                                        >
                                            {{ $binanceModeLabel }}
                                        </x-admin-dashboard.button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                        <form method="POST" action="{{ $paymentControl['update_url'] }}" class="mt-3 space-y-3" aria-label="Batas waktu pembayaran">
                            @csrf
                            <input type="hidden" name="payment_mode" value="{{ $paymentControl['requested_mode'] }}">
                            <input type="hidden" name="update_scope" value="binance_settings">
                            <input type="hidden" name="binance_pay_enabled" value="{{ $paymentControl['binance_pay_enabled'] ? 1 : 0 }}">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-bold text-neo-ink">
                                    Batas bayar QRIS (menit)
                                    <x-admin-dashboard.input
                                        class="mt-1"
                                        min="1"
                                        max="1440"
                                        name="qris_payment_ttl_minutes"
                                        type="number"
                                        :value="old('qris_payment_ttl_minutes', $paymentControl['qris_payment_ttl_minutes'])"
                                    />
                                    @error('qris_payment_ttl_minutes', 'paymentMode')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </label>
                                <label class="block text-xs font-bold text-neo-ink">
                                    Batas bayar Binance Pay (menit)
                                    <x-admin-dashboard.input
                                        class="mt-1"
                                        min="1"
                                        max="1440"
                                        name="binance_payment_ttl_minutes"
                                        type="number"
                                        :value="old('binance_payment_ttl_minutes', $paymentControl['binance_payment_ttl_minutes'])"
                                    />
                                    @error('binance_payment_ttl_minutes', 'paymentMode')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </label>
                            </div>
                            <p class="text-[11px] font-semibold text-brand-muted">Berlaku untuk checkout baru dan mengikuti readiness provider aktif.</p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">
                                Simpan batas waktu pembayaran
                            </x-admin-dashboard.button>
                        </form>
                        <form id="admin-support-settings" data-admin-return-target tabindex="-1" method="POST" action="{{ $paymentControl['support_update_url'] }}" class="mt-4 space-y-3 border-t-[2px] border-neo-ink/15 pt-4" aria-label="Support WhatsApp">
                            @csrf
                            <div>
                                <h3 class="font-display text-sm font-black uppercase tracking-wider text-neo-ink">Support WhatsApp</h3>
                                <p class="mt-1 text-[11px] font-semibold text-brand-muted">
                                    Nomor publik toko disimpan di dashboard. Env hanya menjadi fallback kalau setting ini kosong.
                                </p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-bold text-neo-ink">
                                    Nomor WhatsApp
                                    <x-admin-dashboard.input
                                        class="mt-1"
                                        inputmode="tel"
                                        name="whatsapp_support_number"
                                        placeholder="081234567890"
                                        type="text"
                                        :value="old('whatsapp_support_number', $paymentControl['whatsapp_support_number'])"
                                    />
                                    @error('whatsapp_support_number', 'supportSettings')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </label>
                                <label class="block text-xs font-bold text-neo-ink">
                                    URL WhatsApp custom
                                    <x-admin-dashboard.input
                                        class="mt-1"
                                        name="whatsapp_support_url"
                                        placeholder="https://wa.me/6281234567890"
                                        type="url"
                                        :value="old('whatsapp_support_url', $paymentControl['whatsapp_support_url'])"
                                    />
                                    @error('whatsapp_support_url', 'supportSettings')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </label>
                            </div>
                            <p class="text-[11px] font-semibold text-brand-muted">
                                URL custom menang atas nomor. Aktif sekarang:
                                <span class="break-all font-black text-neo-ink">{{ $paymentControl['whatsapp_support_effective_url'] ?: 'Belum dikonfigurasi' }}</span>
                            </p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">
                                Simpan Support WhatsApp
                            </x-admin-dashboard.button>
                        </form>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            Hanya owner/admin yang bisa mengubah mode pembayaran.
                        </x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>

                <x-admin-dashboard.card
                    id="admin-bsc-settings"
                    data-admin-return-target
                    tabindex="-1"
                    as="section"
                    tone="blue"
                    padding="compact"
                    shadow="sm"
                    class="rounded-[1.125rem]"
                    aria-label="BSC Receiving Address"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">USDT BEP20 (BSC)</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">BSC Receiving Address</h2>
                        </div>
                        <x-admin-dashboard.badge :tone="($bsc['readiness']['ready'] ?? false) ? 'emerald' : 'amber'" dot>
                            {{ $bsc['readiness']['label'] ?? 'Belum siap' }}
                        </x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-xs font-semibold leading-5 text-brand-muted">
                        {{ $bsc['readiness']['message'] ?? 'BSC belum dikonfigurasi.' }}
                    </p>
                    <p class="mt-1 text-[11px] font-semibold text-brand-muted">
                        Receiver aktif terverifikasi: {{ $bsc['readiness']['active_receiver_count'] ?? 0 }} ·
                        Chain RPC: {{ $bsc['readiness']['rpc_chain_id'] ?? 'belum dicek' }} ·
                        Decimals: {{ $bsc['readiness']['token_decimals'] ?? 'belum dicek' }}
                    </p>

                    @error('receiver', 'bsc')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    @if ($bsc['can_manage_settings'] ?? false)
                        <form method="POST" action="{{ $bsc['settings_url'] }}" class="mt-4 space-y-3" aria-label="Pengaturan BSC">
                            @csrf
                            <input type="hidden" name="update_scope" value="bsc_settings">
                            <input type="hidden" name="payment_mode" value="{{ $paymentControl['requested_mode'] }}">
                            <input type="hidden" name="bsc_payment_enabled" value="0">
                            <label class="flex items-center gap-2 text-xs font-black text-neo-ink">
                                <input type="checkbox" name="bsc_payment_enabled" value="1" @checked($bsc['enabled'] ?? false) class="h-4 w-4 rounded border-2 border-neo-ink">
                                Aktifkan rail USDT BEP20 jika readiness lengkap
                            </label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-bold text-neo-ink">
                                    TTL BSC (menit)
                                    <x-admin-dashboard.input class="mt-1" min="1" max="1440" name="bsc_payment_ttl_minutes" type="number" :value="$bsc['ttl_minutes']" />
                                </label>
                                <label class="block text-xs font-bold text-neo-ink">
                                    Minimum confirmations
                                    <x-admin-dashboard.input class="mt-1" min="3" max="100" name="bsc_min_confirmations" type="number" :value="$bsc['min_confirmations']" />
                                </label>
                                <label class="block text-xs font-bold text-neo-ink">
                                    Confirmation grace (menit)
                                    <x-admin-dashboard.input class="mt-1" min="0" max="60" name="bsc_confirmation_grace_minutes" type="number" :value="$bsc['confirmation_grace_minutes']" />
                                </label>
                                <label class="block text-xs font-bold text-neo-ink">
                                    Hash attempts
                                    <x-admin-dashboard.input class="mt-1" min="1" max="20" name="bsc_hash_attempt_limit" type="number" :value="$bsc['hash_attempt_limit']" />
                                </label>
                            </div>
                            <input type="hidden" name="bsc_require_finalized_block" value="0">
                            <label class="flex items-center gap-2 text-[11px] font-bold text-brand-muted">
                                <input type="checkbox" name="bsc_require_finalized_block" value="1" @checked($bsc['require_finalized_block'] ?? false) class="h-4 w-4 rounded border-2 border-neo-ink">
                                Tambahkan finalized-block sebagai gate ekstra
                            </label>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">Simpan pengaturan BSC</x-admin-dashboard.button>
                        </form>
                    @endif

                    @if ($bsc['can_manage_receivers'] ?? false)
                        <form method="POST" action="{{ $bsc['add_url'] }}" class="mt-4 space-y-3 border-t-2 border-neo-ink/15 pt-4" aria-label="Tambah BSC receiver">
                            @csrf
                            <div>
                                <p class="text-[11px] font-black uppercase tracking-wider text-brand-muted">Tambah receiver</p>
                                <p class="mt-1 text-[11px] font-semibold text-brand-muted">Simpan address BEP20 yang benar. Address baru langsung aktif untuk pembayaran BSC.</p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-admin-dashboard.input name="label" placeholder="Wallet utama" value="{{ old('label') }}" required />
                                <x-admin-dashboard.input name="address" placeholder="0x..." maxlength="42" inputmode="text" autocapitalize="off" autocomplete="off" spellcheck="false" pattern="0x[0-9A-Fa-f]{40}" value="{{ old('address') }}" required />
                            </div>
                            <x-admin-dashboard.button type="submit" variant="neutral" size="sm" class="w-full text-[11px]">Simpan address</x-admin-dashboard.button>
                        </form>

                        <div class="mt-4 space-y-3 border-t-2 border-neo-ink/15 pt-4">
                            @forelse (($bsc['receivers'] ?? []) as $receiver)
                                <div class="rounded-xl border-2 border-neo-ink/15 bg-white/80 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-black text-neo-ink">{{ $receiver['label'] }}</p>
                                            <p class="mt-1 break-all text-[11px] font-semibold text-brand-muted">{{ $receiver['address'] }}</p>
                                        </div>
                                        <x-admin-dashboard.badge :tone="$receiver['is_active'] ? 'emerald' : 'amber'">
                                            {{ $receiver['is_active'] ? 'Aktif' : $receiver['verification_label'] }}
                                        </x-admin-dashboard.badge>
                                    </div>

                                    @if ($receiver['is_active'])
                                        <form method="POST" action="{{ $receiver['deactivate_url'] }}" class="mt-3">
                                            @csrf
                                            <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="w-full text-[11px]">Nonaktifkan receiver</x-admin-dashboard.button>
                                        </form>
                                    @elseif (in_array($receiver['verification_status'], ['verified', 'manual_verified'], true))
                                        <form method="POST" action="{{ $receiver['activate_url'] }}" class="mt-3">
                                            @csrf
                                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">Aktifkan receiver</x-admin-dashboard.button>
                                        </form>
                                    @elseif ($receiver['verification_status'] === 'pending_verification')
                                        <form method="POST" action="{{ $receiver['manual_verify_url'] }}" class="mt-3">
                                            @csrf
                                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">Aktifkan address</x-admin-dashboard.button>
                                        </form>
                                    @else
                                        <p class="mt-3 text-[11px] font-semibold text-brand-muted">Address belum bisa dipakai. Tambahkan address baru.</p>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs font-semibold text-brand-muted">Belum ada BSC Receiving Address.</p>
                            @endforelse
                        </div>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-4 text-xs">Hanya Owner yang dapat menambah, mengaktifkan, atau menonaktifkan destination address BSC.</x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>

                <x-admin-dashboard.card
                    id="admin-store-balance-funding-policy"
                    data-admin-return-target
                    tabindex="-1"
                    as="section"
                    tone="blue"
                    padding="compact"
                    shadow="sm"
                    class="rounded-[1.125rem]"
                    aria-label="Kebijakan Top-up Saldo"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Store Balance</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">Kebijakan Top-up Saldo</h2>
                        </div>
                        <x-admin-dashboard.badge tone="blue" dot>VERSI {{ $fundingPolicy['version'] ?? 0 }}</x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-xs font-semibold leading-5 text-brand-muted">
                        Minimum saat ini:
                        <span class="font-black text-neo-ink">{{ $fundingPolicy['minimum_formatted'] ?? '—' }}</span>
                    </p>
                    <div class="mt-3 rounded-xl border-2 border-neo-ink/15 bg-white/80 p-3">
                        <p class="text-[11px] font-black uppercase tracking-wider text-brand-muted">Preset nominal berurutan</p>
                        <ol class="mt-2 grid gap-1 text-sm font-black text-neo-ink sm:grid-cols-2">
                            @foreach (($fundingPolicy['preset_amounts_formatted'] ?? []) as $amount)
                                <li>{{ $amount }}</li>
                            @endforeach
                        </ol>
                    </div>

                    @error('minimum_amount', 'fundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('preset_amounts', 'fundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('version', 'fundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    @if ($fundingPolicy['can_manage'] ?? false)
                        <form method="POST" action="{{ $fundingPolicy['update_url'] }}" class="mt-4 space-y-3" aria-label="Kebijakan top-up Saldo">
                            @csrf
                            <input type="hidden" name="version" value="{{ $fundingPolicy['version'] }}">
                            <label class="block text-xs font-bold text-neo-ink">
                                Minimum top-up (IDR)
                                <x-admin-dashboard.input class="mt-1" name="minimum_amount" inputmode="numeric" autocomplete="off" :value="old('minimum_amount', $fundingPolicy['minimum_amount'])" required />
                            </label>
                            <label class="block text-xs font-bold text-neo-ink">
                                Preset top-up (satu nominal per baris)
                                <textarea name="preset_amounts" rows="6" inputmode="numeric" class="neo-input mt-1 min-h-32 w-full bg-white px-3 py-2 text-sm font-semibold" autocomplete="off" required>{{ is_array(old('preset_amounts')) ? implode("\n", old('preset_amounts')) : old('preset_amounts', $fundingPolicy['preset_amounts_input'] ?? '') }}</textarea>
                            </label>
                            <p class="text-[11px] font-semibold leading-5 text-brand-muted">Gunakan angka rupiah utuh tanpa titik, koma, atau desimal. Maksimal {{ \App\Models\StoreBalanceFundingPolicy::MAX_PRESETS }} preset; semua preset harus unik, menaik, dan tidak kurang dari minimum.</p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">
                                Simpan kebijakan top-up
                            </x-admin-dashboard.button>
                        </form>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            Finance dan Support dapat melihat kebijakan, tetapi tidak dapat mengubah minimum atau preset top-up.
                        </x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>

                @php($usdtFundingEnabledInput = old('funding_enabled', ($usdtFundingPolicy['funding_enabled'] ?? false) ? '1' : '0'))
                <x-admin-dashboard.card
                    id="admin-usdt-funding-policy"
                    data-admin-return-target
                    tabindex="-1"
                    as="section"
                    tone="blue"
                    padding="compact"
                    shadow="sm"
                    class="rounded-[1.125rem]"
                    aria-label="Kebijakan Top-up USDT"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Customer USDT</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">Kebijakan Top-up USDT</h2>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <x-admin-dashboard.badge tone="blue" dot>VERSI {{ $usdtFundingPolicy['version'] ?? 0 }}</x-admin-dashboard.badge>
                            @if ($usdtFundingPolicy['funding_enabled'] ?? false)
                                <x-admin-dashboard.badge tone="emerald">AKTIF</x-admin-dashboard.badge>
                            @else
                                <x-admin-dashboard.badge tone="amber">NONAKTIF</x-admin-dashboard.badge>
                            @endif
                        </div>
                    </div>

                    <p class="mt-2 text-xs font-semibold leading-5 text-brand-muted">
                        Kebijakan H0-v1 tanpa minimum/maksimum bisnis dan tanpa preset; fee aplikasi
                        <span class="font-black text-neo-ink">{{ $usdtFundingPolicy['application_fee_display'] ?? '0 USDT' }}</span>,
                        konfirmasi {{ $usdtFundingPolicy['confirmation_ttl_seconds'] ?? 600 }} detik, satu target aktif per Customer lintas rail.
                        Syarat versi {{ $usdtFundingPolicy['terms_version'] ?? 1 }} disnapshot ke tiap konfirmasi.
                    </p>

                    <div class="mt-3 space-y-2">
                        @foreach (($usdtFundingPolicy['rails'] ?? []) as $rail)
                            <div class="flex items-start justify-between gap-2 rounded-xl border-2 border-neo-ink/15 bg-white/80 p-2">
                                <div class="min-w-0">
                                    <p class="text-[11px] font-black text-neo-ink">{{ $rail['label'] }}</p>
                                    <p class="text-[11px] font-semibold text-brand-muted">
                                        {{ $rail['reason'] }}@if (! empty($rail['detail'])) &middot; {{ $rail['detail'] }}@endif
                                    </p>
                                </div>
                                @if ($rail['available'])
                                    <x-admin-dashboard.badge tone="emerald">SIAP</x-admin-dashboard.badge>
                                @else
                                    <x-admin-dashboard.badge tone="amber">DITUTUP</x-admin-dashboard.badge>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 space-y-2">
                        @foreach (($usdtFundingPolicy['pauses'] ?? []) as $pause)
                            <div class="rounded-xl border-2 border-neo-ink/15 bg-white/80 p-2">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[11px] font-black text-neo-ink">{{ $pause['label'] }}</p>
                                    @if ($pause['paused'])
                                        <x-admin-dashboard.badge tone="rose">DIJEDA</x-admin-dashboard.badge>
                                    @else
                                        <x-admin-dashboard.badge tone="emerald">BERJALAN</x-admin-dashboard.badge>
                                    @endif
                                </div>
                                @if ($pause['paused'])
                                    <p class="mt-1 text-[11px] font-semibold text-brand-muted">{{ $pause['reason'] }} &middot; {{ $pause['paused_at'] }}</p>
                                @endif
                                @if ($usdtFundingPolicy['can_manage'] ?? false)
                                    @if ($pause['paused'])
                                        <form method="POST" action="{{ route('admin.store-balance.usdt-pauses.destroy', $pause['scope']) }}" class="mt-2">
                                            @csrf
                                            @method('DELETE')
                                            <x-admin-dashboard.button type="submit" variant="secondary" size="sm" class="w-full text-[11px]">Lanjutkan</x-admin-dashboard.button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.store-balance.usdt-pauses.store', $pause['scope']) }}" class="mt-2 space-y-2">
                                            @csrf
                                            <x-admin-dashboard.input name="reason" :value="old('reason')" placeholder="Alasan jeda (wajib)" required />
                                            <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="w-full text-[11px]">Jeda</x-admin-dashboard.button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @error('funding_enabled', 'usdtFundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('confirmation_ttl_seconds', 'usdtFundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('application_fee_micro', 'usdtFundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('terms_body', 'usdtFundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('version', 'usdtFundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('reason', 'usdtFundingPolicy')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    @if ($usdtFundingPolicy['can_manage'] ?? false)
                        <form method="POST" action="{{ $usdtFundingPolicy['update_url'] ?? route('admin.store-balance.usdt-policy.update') }}" class="mt-4 space-y-3" aria-label="Kebijakan top-up USDT">
                            @csrf
                            <input type="hidden" name="version" value="{{ $usdtFundingPolicy['version'] ?? 0 }}">
                            <label class="block text-xs font-bold text-neo-ink">
                                Status top-up USDT
                                <x-admin-dashboard.select class="mt-1" name="funding_enabled" required>
                                    <option value="0" @selected((string) $usdtFundingEnabledInput !== '1')>Nonaktif (aktifkan hanya setelah rail qualified)</option>
                                    <option value="1" @selected((string) $usdtFundingEnabledInput === '1')>Aktif</option>
                                </x-admin-dashboard.select>
                            </label>
                            <label class="block text-xs font-bold text-neo-ink">
                                Masa konfirmasi (detik, 60-3600)
                                <x-admin-dashboard.input class="mt-1" name="confirmation_ttl_seconds" inputmode="numeric" autocomplete="off" :value="old('confirmation_ttl_seconds', $usdtFundingPolicy['confirmation_ttl_seconds'] ?? 600)" required />
                            </label>
                            <label class="block text-xs font-bold text-neo-ink">
                                Fee aplikasi (USDT, maksimal 6 desimal)
                                <x-admin-dashboard.input class="mt-1" name="application_fee_micro" inputmode="decimal" autocomplete="off" :value="old('application_fee_micro', $usdtFundingPolicy['application_fee_input'] ?? '0')" required />
                            </label>
                            <label class="block text-xs font-bold text-neo-ink">
                                Syarat top-up USDT (versi disnapshot ke konfirmasi)
                                <x-admin-dashboard.textarea class="mt-1" name="terms_body" :rows="6" :value="old('terms_body', $usdtFundingPolicy['terms_body'] ?? '')" required />
                            </label>
                            <p class="text-[11px] font-semibold leading-5 text-brand-muted">Tidak ada batas minimum, maksimum, kuota, atau allowlist bisnis pada rilis ini (H0-v1). Perubahan kebijakan tidak pernah memindahkan saldo.</p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">
                                Simpan kebijakan top-up USDT
                            </x-admin-dashboard.button>
                        <a href="{{ route('admin.store-balance.usdt-review.index') }}" class="inline-flex min-h-11 items-center font-bold underline">Review deposit USDT dan insiden</a>
                        </form>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            Hanya Owner yang dapat mengubah kebijakan dan jeda top-up USDT.
                        </x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>

                @if ($canManageBalanceAdjustments)
                    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Penambahan saldo Customer">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Owner</p>
                                <h2 class="mt-1 font-display text-base font-bold text-neo-ink">Penambahan saldo Customer</h2>
                                <p class="mt-1 text-xs font-semibold leading-5 text-brand-muted">Cari dengan Telegram ID exact, periksa preview, lalu konfirmasi dengan password Owner.</p>
                            </div>
                            <a href="{{ route('admin.store-balance.adjustments.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border-2 border-neo-ink bg-brand-yellow px-4 py-2 text-sm font-black text-neo-ink shadow-neo-sm transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus sm:w-auto">Kelola saldo</a>
                        </div>
                    </x-admin-dashboard.card>
                @endif

                @if ($canViewBalanceReports)
                    <x-admin-dashboard.card as="section" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Laporan saldo Customer">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Customer balance</p>
                                <h2 class="mt-1 font-display text-base font-bold text-neo-ink">Laporan saldo, ledger, top-up, review, dan treasury</h2>
                                <p class="mt-1 text-xs font-semibold leading-5 text-brand-muted">Akses baca-saja dengan filter Telegram ID exact, saldo IDR/USDT terpisah, dan referensi tersamarkan.</p>
                            </div>
                            <a href="{{ route('admin.store-balance.reports.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border-2 border-neo-ink bg-brand-yellow px-4 py-2 text-sm font-black text-neo-ink shadow-neo-sm sm:w-auto">Buka laporan saldo</a>
                        </div>
                    </x-admin-dashboard.card>
                @endif

                <x-admin-dashboard.card
                    id="admin-checkout-maintenance"
                    data-admin-return-target
                    tabindex="-1"
                    tone="{{ $checkoutMaintenance['active'] ? 'rose' : 'green' }}"
                    padding="compact"
                    shadow="sm"
                    class="rounded-[1.125rem]"
                    aria-label="Checkout Maintenance"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Checkout Maintenance</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">{{ $checkoutMaintenance['label'] }}</h2>
                        </div>
                        <x-admin-dashboard.badge :tone="$checkoutMaintenance['tone']" dot>
                            {{ $checkoutMaintenance['status_badge']['label'] }}
                        </x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-xs font-semibold leading-5 text-brand-muted">{{ $checkoutMaintenance['hint'] }}</p>
                    @if ($checkoutMaintenance['activated_at'])
                        <p class="mt-2 text-[11px] font-bold text-neo-ink">Aktif sejak {{ $checkoutMaintenance['activated_at'] }}</p>
                    @endif
                    @if ($checkoutMaintenance['public_note'])
                        <div class="mt-3 rounded-xl border-2 border-neo-ink/15 bg-white/80 p-3">
                            <p class="text-[10px] font-black uppercase tracking-wider text-brand-muted">Catatan publik</p>
                            <p class="mt-1 whitespace-pre-line text-sm font-semibold leading-6 text-neo-ink">{{ $checkoutMaintenance['public_note'] }}</p>
                        </div>
                    @endif

                    @error('active', 'checkoutMaintenance')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('version', 'checkoutMaintenance')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('public_note', 'checkoutMaintenance')
                        <x-admin-dashboard.field-error class="mt-3">{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    @if ($checkoutMaintenance['can_manage'])
                        @if ($checkoutMaintenance['active'])
                            <form
                                method="POST"
                                action="{{ $checkoutMaintenance['update_url'] }}"
                                class="mt-4 space-y-3"
                                data-checkout-maintenance-target="update"
                                x-on:submit.prevent="openCheckoutMaintenanceConfirmation($event)"
                            >
                                @csrf
                                <input type="hidden" name="active" value="1">
                                <input type="hidden" name="confirmed" value="0">
                                <input type="hidden" name="version" value="{{ $checkoutMaintenance['version'] }}">
                                <label class="block text-xs font-bold text-neo-ink">
                                    Catatan publik (opsional)
                                    <textarea name="public_note" maxlength="500" rows="3" class="neo-input mt-1 min-h-24 w-full bg-white px-3 py-2 text-sm font-semibold" placeholder="Contoh: Checkout dibuka kembali pukul 14.00.">{{ old('public_note', $checkoutMaintenance['public_note']) }}</textarea>
                                </label>
                                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">
                                    Simpan catatan
                                </x-admin-dashboard.button>
                            </form>
                            <form
                                method="POST"
                                action="{{ $checkoutMaintenance['update_url'] }}"
                                class="mt-2"
                                data-checkout-maintenance-target="deactivate"
                                x-on:submit.prevent="openCheckoutMaintenanceConfirmation($event)"
                            >
                                @csrf
                                <input type="hidden" name="active" value="0">
                                <input type="hidden" name="confirmed" value="0">
                                <input type="hidden" name="version" value="{{ $checkoutMaintenance['version'] }}">
                                <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="w-full text-[11px]">
                                    Nonaktifkan Checkout Maintenance
                                </x-admin-dashboard.button>
                            </form>
                        @else
                            <form
                                method="POST"
                                action="{{ $checkoutMaintenance['update_url'] }}"
                                class="mt-4 space-y-3"
                                data-checkout-maintenance-target="activate"
                                x-on:submit.prevent="openCheckoutMaintenanceConfirmation($event)"
                            >
                                @csrf
                                <input type="hidden" name="active" value="1">
                                <input type="hidden" name="version" value="{{ $checkoutMaintenance['version'] }}">
                                <input type="hidden" name="confirmed" value="0">
                                <label class="block text-xs font-bold text-neo-ink">
                                    Catatan publik (opsional)
                                    <textarea name="public_note" maxlength="500" rows="3" class="neo-input mt-1 min-h-24 w-full bg-white px-3 py-2 text-sm font-semibold" placeholder="Contoh: Checkout dibuka kembali pukul 14.00.">{{ old('public_note', $checkoutMaintenance['public_note']) }}</textarea>
                                </label>
                                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full text-[11px]">
                                    Aktifkan Checkout Maintenance
                                </x-admin-dashboard.button>
                            </form>
                        @endif
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            Finance dan Support dapat melihat status, tetapi tidak dapat mengubah Checkout Maintenance.
                        </x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>

                @if ($checkoutMaintenance['can_manage'])
                    <div
                        x-cloak
                        x-show="checkoutMaintenanceOpen"
                        x-transition.opacity
                        class="fixed inset-0 z-[70] flex items-center justify-center bg-neo-ink/60 p-3 sm:p-4"
                        x-on:keydown.escape.window="if (checkoutMaintenanceOpen) closeCheckoutMaintenanceConfirmation()"
                    >
                        <div class="absolute inset-0" x-on:click="closeCheckoutMaintenanceConfirmation()" aria-hidden="true"></div>
                        <div
                            id="admin-checkout-maintenance-dialog"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="admin-checkout-maintenance-title"
                            aria-describedby="admin-checkout-maintenance-description"
                            tabindex="-1"
                            x-on:keydown.tab="trapModalFocus($event)"
                            x-on:focusin.window="if (checkoutMaintenanceOpen) keepModalFocus($event, 'admin-checkout-maintenance-dialog')"
                            class="relative max-h-[calc(100dvh-1.5rem)] w-full max-w-lg overflow-y-auto rounded-[1.25rem] border-[4px] border-neo-ink bg-neo-paper p-4 text-neo-ink shadow-neo-xl sm:p-5"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 pr-8">
                                    <p class="font-display text-[10px] font-black uppercase tracking-[0.18em] text-brand-muted">Checkout Maintenance</p>
                                    <h2 id="admin-checkout-maintenance-title" class="mt-1 font-display text-xl font-black uppercase leading-tight">Konfirmasi perubahan Checkout Maintenance</h2>
                                </div>
                                <button type="button" class="absolute right-3 top-3 inline-flex h-10 w-10 items-center justify-center rounded-[0.45rem] border-[3px] border-neo-ink bg-white text-lg font-black shadow-neo-sm" aria-label="Tutup dialog Checkout Maintenance" x-on:click="closeCheckoutMaintenanceConfirmation()">×</button>
                            </div>
                            <div id="admin-checkout-maintenance-description" class="mt-4 space-y-3 text-sm font-bold leading-6">
                                <p x-text="checkoutMaintenanceConfirmationText"></p>
                                <p class="rounded-xl border-2 border-neo-ink/20 bg-white/80 p-3 text-xs font-semibold leading-5">
                                    Katalog, stok, keranjang, dan Order yang sudah ada tetap tersedia. Hanya pembuatan Order baru yang terpengaruh.
                                </p>
                            </div>
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeCheckoutMaintenanceConfirmation()">
                                    Batal
                                </x-admin-dashboard.button>
                                <x-admin-dashboard.button type="button" variant="primary" x-ref="checkoutMaintenanceConfirm" x-on:click="submitCheckoutMaintenanceConfirmation()">
                                    Konfirmasi
                                </x-admin-dashboard.button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
