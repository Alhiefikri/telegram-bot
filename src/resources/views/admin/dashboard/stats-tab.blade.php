            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  STATS TAB (Ringkasan)                              ║
                 ╚═════════════════════════════════════════════════════╝ --}}
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

                    @if (! $paymentControl['is_pakasir_configured'])
                        <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">
                            {{ $paymentControl['pakasir_guard_message'] }}
                        </x-admin-dashboard.notice>
                    @endif

                    @error('payment_mode', 'paymentMode')
                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                    @enderror
                    @error('pakasir_checkout_flow', 'paymentMode')
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
                                </p>
                                @if (! $providerStatus['ready'])
                                    <p class="mt-1 text-[10px] font-semibold text-brand-muted">{{ $providerStatus['reason'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 rounded-xl border-2 border-amber-700/30 bg-amber-50 p-3 text-[11px] font-semibold text-amber-950">
                        <p class="font-black">Peringatan operasional Pakasir</p>
                        <p class="mt-1">{{ $paymentControl['pakasir_cutoff']['message'] }}</p>
                        @unless ($paymentControl['pakasir_cutoff']['is_past'])
                            <p class="mt-1">Countdown: {{ $paymentControl['pakasir_cutoff']['days_remaining'] }} hari ({{ $paymentControl['pakasir_cutoff']['hours_remaining'] }} jam) hingga 15 Agustus 2026.</p>
                        @endunless
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
                        <div class="mt-3 grid gap-2 md:grid-cols-4">
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
                            <form method="POST" action="{{ $paymentControl['update_url'] }}" data-provider-switch-from="{{ $paymentControl['requested_provider_label'] }}" data-provider-switch-target="Pakasir hosted" x-on:submit.prevent="openPaymentProviderSwitch($event)">
                                @csrf
                                <input type="hidden" name="update_scope" value="provider_switch">
                                <input type="hidden" name="payment_mode" value="pakasir">
                                <input type="hidden" name="pakasir_checkout_flow" value="hosted">
                                <input type="hidden" name="payment_provider_version" value="{{ $paymentControl['payment_provider_version'] }}">
                                <input type="hidden" name="confirm_provider_switch" value="1">
                                <x-admin-dashboard.button type="submit" :variant="$paymentControl['requested_mode'] === 'pakasir' && $paymentControl['requested_checkout_flow'] === 'hosted' ? 'primary' : 'neutral'" size="sm" class="w-full text-[11px] disabled:cursor-not-allowed disabled:opacity-60" :disabled="! $paymentControl['can_enable_pakasir']" title="{{ $paymentControl['pakasir_guard_message'] }}">
                                    Hosted Pakasir
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
                            <form method="POST" action="{{ $paymentControl['update_url'] }}" data-provider-switch-from="{{ $paymentControl['requested_provider_label'] }}" data-provider-switch-target="Pakasir native QRIS" x-on:submit.prevent="openPaymentProviderSwitch($event)">
                                @csrf
                                <input type="hidden" name="update_scope" value="provider_switch">
                                <input type="hidden" name="payment_mode" value="pakasir">
                                <input type="hidden" name="pakasir_checkout_flow" value="api_qris">
                                <input type="hidden" name="payment_provider_version" value="{{ $paymentControl['payment_provider_version'] }}">
                                <input type="hidden" name="confirm_provider_switch" value="1">
                                <x-admin-dashboard.button type="submit" :variant="$paymentControl['requested_mode'] === 'pakasir' && $paymentControl['requested_checkout_flow'] === 'api_qris' ? 'purple' : 'neutral'" size="sm" class="w-full text-[11px] disabled:cursor-not-allowed disabled:opacity-60" :disabled="! $paymentControl['can_enable_pakasir']" title="{{ $paymentControl['pakasir_guard_message'] }}">
                                    QR Native Pakasir
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
                            @if ($paymentControl['requested_mode'] === 'pakasir')
                                <input type="hidden" name="pakasir_checkout_flow" value="{{ $paymentControl['requested_checkout_flow'] }}">
                            @endif
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
                                        @if ($paymentControl['requested_mode'] === 'pakasir')
                                            <input type="hidden" name="pakasir_checkout_flow" value="{{ $paymentControl['requested_checkout_flow'] }}">
                                        @endif
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
                            @if ($paymentControl['requested_mode'] === 'pakasir')
                                <input type="hidden" name="pakasir_checkout_flow" value="{{ $paymentControl['requested_checkout_flow'] }}">
                            @endif
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
                            <p class="text-[11px] font-semibold text-brand-muted">Berlaku untuk checkout baru. QRIS Pakasir tetap mengikuti batas waktu provider jika lebih cepat.</p>
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
            </div>
