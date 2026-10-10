<div id="admin-section-settings" data-admin-return-target tabindex="-1" class="mt-4 space-y-5">
    <header>
        <h2 class="text-2xl font-semibold tracking-tight text-admin-ink">Pengaturan toko</h2>
        <p class="mt-1 text-sm text-admin-muted">Atur pembayaran dan saldo di sini. Ringkasan tetap fokus pada penjualan.</p>
    </header>
    <nav class="flex flex-wrap gap-2" aria-label="Bagian pengaturan">
        @foreach (['pembayaran' => 'Pembayaran & toko', 'saldo' => 'Saldo & top-up', 'diagnostik' => 'Diagnostik saldo'] as $section => $label)
            <x-admin-dashboard.button :href="route('admin.dashboard', ['tab' => 'settings', 'section' => $section])" :variant="$settingsSection === $section ? 'primary' : 'neutral'" :aria-current="$settingsSection === $section ? 'page' : null">{{ $label }}</x-admin-dashboard.button>
        @endforeach
    </nav>
    @if ($settingsSection === 'pembayaran')
        @php($bsc = $paymentControl['bsc'] ?? [])
                <x-admin-dashboard.card id="admin-payment-settings" data-admin-return-target tabindex="-1" as="section" padding="compact" shadow="sm" aria-label="Mode pembayaran">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-admin-muted">Mode pembayaran</p>
                            <h2 class="mt-1 text-lg font-semibold text-admin-ink">{{ $paymentControl['label'] }}</h2>
                        </div>
                        <x-admin-dashboard.badge
                            :tone="$paymentControl['tone']"
                            dot
                        >
                            {{ $paymentControl['status_badge']['label'] }}
                        </x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-sm leading-6 text-admin-muted">
                        {{ $paymentControl['hint'] }}
                    </p>


                    @error('payment_mode', 'paymentMode')
                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                    @enderror

                    <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                        <p class="text-xs font-medium text-admin-muted">Provider requested / effective</p>
                        <p class="mt-1 text-sm font-semibold text-admin-ink">
                            {{ $paymentControl['requested_provider_label'] }}
                            <span class="font-medium text-admin-muted">· efektif {{ $paymentControl['effective_provider_label'] }}</span>
                        </p>
                        <p class="mt-1 text-xs font-medium text-admin-muted">{{ $paymentControl['hint'] }}</p>
                    </div>

                    <div class="mt-3 grid gap-2 sm:grid-cols-2" aria-label="Readiness dan antrean provider">
                        @foreach ($paymentControl['providers'] as $provider => $providerStatus)
                            <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-medium text-admin-ink">{{ $providerStatus['label'] }}</span>
                                    <x-admin-dashboard.badge :tone="$providerStatus['ready'] ? 'emerald' : 'amber'">
                                        {{ $providerStatus['ready'] ? 'Siap' : 'Belum siap' }}
                                    </x-admin-dashboard.badge>
                                </div>
                                <p class="mt-2 text-xs font-medium text-admin-muted">
                                    Pending: {{ $paymentControl['pending_by_provider'][$provider] ?? 0 }}
                                    @if ($paymentControl['oldest_pending_by_provider'][$provider] ?? null)
                                        · tertua {{ $paymentControl['oldest_pending_by_provider'][$provider] }}
                                    @endif
                                    @if ($provider === 'sakurupiah' && ($paymentControl['sakurupiah_backlog']['verification_pending'] ?? 0) > 0)
                                        · <span class="font-semibold text-neo-warning-text">verifikasi {{ $paymentControl['sakurupiah_backlog']['verification_pending'] }}</span>
                                    @endif
                                </p>
                                @if (! $providerStatus['ready'])
                                    <p class="mt-1 text-xs font-medium text-admin-muted">{{ $providerStatus['reason'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>


                    @if ($paymentControl['switch_audit'] !== [])
                        <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                            <p class="text-xs font-medium text-admin-muted">Audit provider terakhir</p>
                            <div class="mt-2 space-y-1 text-xs font-medium text-admin-muted">
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
                        <p class="mt-3 text-xs font-medium text-admin-muted">
                            Setiap perubahan meminta konfirmasi provider lama → baru dan hanya memengaruhi checkout baru.
                        </p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <form method="POST" action="{{ $paymentControl['update_url'] }}" data-provider-switch-from="{{ $paymentControl['requested_provider_label'] }}" data-provider-switch-target="Manual" x-on:submit.prevent="openPaymentProviderSwitch($event)">
                                @csrf
                                <input type="hidden" name="update_scope" value="provider_switch">
                                <input type="hidden" name="payment_mode" value="manual">
                                <input type="hidden" name="payment_provider_version" value="{{ $paymentControl['payment_provider_version'] }}">
                                <input type="hidden" name="confirm_provider_switch" value="1">
                                <x-admin-dashboard.button type="submit" :variant="$paymentControl['requested_mode'] === 'manual' ? 'warning' : 'neutral'" size="sm" class="w-full">
                                    QR Manual
                                </x-admin-dashboard.button>
                            </form>
                            <form method="POST" action="{{ $paymentControl['update_url'] }}" data-provider-switch-from="{{ $paymentControl['requested_provider_label'] }}" data-provider-switch-target="Sakurupiah" x-on:submit.prevent="openPaymentProviderSwitch($event)">
                                @csrf
                                <input type="hidden" name="update_scope" value="provider_switch">
                                <input type="hidden" name="payment_mode" value="sakurupiah">
                                <input type="hidden" name="payment_provider_version" value="{{ $paymentControl['payment_provider_version'] }}">
                                <input type="hidden" name="confirm_provider_switch" value="1">
                                <x-admin-dashboard.button type="submit" :variant="$paymentControl['requested_mode'] === 'sakurupiah' ? 'purple' : 'neutral'" size="sm" class="w-full disabled:cursor-not-allowed disabled:opacity-60" :disabled="! $paymentControl['can_enable_sakurupiah']" title="{{ $paymentControl['sakurupiah_guard_message'] }}">
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
                                class="relative max-h-[calc(100dvh-1.5rem)] w-full max-w-lg overflow-y-auto rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-4 text-admin-ink shadow-admin-lg sm:p-5"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 pr-8">
                                        <p class="text-xs font-medium text-admin-muted">Payment Provider Switch</p>
                                        <h2 id="admin-payment-provider-switch-title" class="mt-1 text-lg font-semibold leading-tight text-admin-ink">Konfirmasi ganti provider pembayaran</h2>
                                    </div>
                                    <button
                                        type="button"
                                        class="absolute right-3 top-3 inline-flex h-11 w-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface text-lg text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus"
                                        aria-label="Tutup dialog provider"
                                        x-on:click="closePaymentProviderSwitch()"
                                    >×</button>
                                </div>

                                <div id="admin-payment-provider-switch-description" class="mt-4 space-y-3 text-sm font-normal leading-6 text-admin-ink">
                                    <p>
                                        Ubah provider dari
                                        <span class="font-semibold" x-text="paymentSwitchCurrentLabel"></span>
                                        ke
                                        <span class="font-semibold" x-text="paymentSwitchTargetLabel"></span>?
                                    </p>
                                    <p class="rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-xs font-medium leading-5 text-admin-muted">
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
                            <x-admin-dashboard.button type="submit" :variant="$paymentControl['binance_pay_enabled'] ? 'primary' : 'neutral'" size="sm" class="w-full">
                                {{ $paymentControl['binance_pay_enabled'] ? 'Binance Pay USDT: Aktif' : 'Aktifkan Binance Pay USDT' }}
                            </x-admin-dashboard.button>
                        </form>
                        <div class="mt-4 border-t border-admin-line pt-4">
                            <h3 class="text-sm font-semibold text-admin-ink">Verifikasi Binance</h3>
                            <p class="mt-1 text-xs font-medium text-admin-muted">Pilih Order ID setelah API key, secret, dan Binance Pay ID atau UID dikonfigurasi. Amount unik tetap memakai nominal berurutan.</p>
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
                                            class="w-full"
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
                                <label class="block text-xs font-medium text-admin-ink">
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
                                <label class="block text-xs font-medium text-admin-ink">
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
                            <p class="text-xs font-medium text-admin-muted">Berlaku untuk checkout baru dan mengikuti readiness provider aktif.</p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">
                                Simpan batas waktu pembayaran
                            </x-admin-dashboard.button>
                        </form>
                        <form id="admin-support-settings" data-admin-return-target tabindex="-1" method="POST" action="{{ $paymentControl['support_update_url'] }}" class="mt-4 space-y-3 border-t border-admin-line pt-4" aria-label="Support WhatsApp">
                            @csrf
                            <div>
                                <h3 class="text-sm font-semibold text-admin-ink">Support WhatsApp</h3>
                                <p class="mt-1 text-xs font-medium text-admin-muted">
                                    Nomor publik toko disimpan di dashboard. Env hanya menjadi fallback kalau setting ini kosong.
                                </p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-medium text-admin-ink">
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
                                <label class="block text-xs font-medium text-admin-ink">
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
                            <p class="text-xs font-medium text-admin-muted">
                                URL custom menang atas nomor. Aktif sekarang:
                                <span class="break-all font-semibold text-admin-ink">{{ $paymentControl['whatsapp_support_effective_url'] ?: 'Belum dikonfigurasi' }}</span>
                            </p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">
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
                    padding="compact"
                    shadow="sm"
                    aria-label="BSC Receiving Address"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-admin-muted">USDT BEP20 (BSC)</p>
                            <h2 class="mt-1 text-lg font-semibold text-admin-ink">BSC Receiving Address</h2>
                        </div>
                        <x-admin-dashboard.badge :tone="($bsc['readiness']['ready'] ?? false) ? 'emerald' : 'amber'" dot>
                            {{ $bsc['readiness']['label'] ?? 'Belum siap' }}
                        </x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-sm leading-6 text-admin-muted">
                        {{ $bsc['readiness']['message'] ?? 'BSC belum dikonfigurasi.' }}
                    </p>
                    <p class="mt-1 text-xs font-medium text-admin-muted">
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
                            <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink">
                                <input type="checkbox" name="bsc_payment_enabled" value="1" @checked($bsc['enabled'] ?? false) class="h-5 w-5 rounded border-admin-line accent-admin-accent">
                                Aktifkan rail USDT BEP20 jika readiness lengkap
                            </label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-medium text-admin-ink">
                                    TTL BSC (menit)
                                    <x-admin-dashboard.input class="mt-1" min="1" max="1440" name="bsc_payment_ttl_minutes" type="number" :value="$bsc['ttl_minutes']" />
                                </label>
                                <label class="block text-xs font-medium text-admin-ink">
                                    Minimum confirmations
                                    <x-admin-dashboard.input class="mt-1" min="3" max="100" name="bsc_min_confirmations" type="number" :value="$bsc['min_confirmations']" />
                                </label>
                                <label class="block text-xs font-medium text-admin-ink">
                                    Confirmation grace (menit)
                                    <x-admin-dashboard.input class="mt-1" min="0" max="60" name="bsc_confirmation_grace_minutes" type="number" :value="$bsc['confirmation_grace_minutes']" />
                                </label>
                                <label class="block text-xs font-medium text-admin-ink">
                                    Hash attempts
                                    <x-admin-dashboard.input class="mt-1" min="1" max="20" name="bsc_hash_attempt_limit" type="number" :value="$bsc['hash_attempt_limit']" />
                                </label>
                            </div>
                            <input type="hidden" name="bsc_require_finalized_block" value="0">
                            <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink">
                                <input type="checkbox" name="bsc_require_finalized_block" value="1" @checked($bsc['require_finalized_block'] ?? false) class="h-5 w-5 rounded border-admin-line accent-admin-accent">
                                Tambahkan finalized-block sebagai gate ekstra
                            </label>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">Simpan pengaturan BSC</x-admin-dashboard.button>
                        </form>
                    @endif

                    @if ($bsc['can_manage_receivers'] ?? false)
                        <form method="POST" action="{{ $bsc['add_url'] }}" class="mt-4 space-y-3 border-t border-admin-line pt-4" aria-label="Tambah BSC receiver">
                            @csrf
                            <div>
                                <p class="text-xs font-medium text-admin-muted">Tambah receiver</p>
                                <p class="mt-1 text-xs font-medium text-admin-muted">Simpan address BEP20 yang benar. Address baru langsung aktif untuk pembayaran BSC.</p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-admin-dashboard.input name="label" placeholder="Wallet utama" value="{{ old('label') }}" required />
                                <x-admin-dashboard.input name="address" placeholder="0x..." maxlength="42" inputmode="text" autocapitalize="off" autocomplete="off" spellcheck="false" pattern="0x[0-9A-Fa-f]{40}" value="{{ old('address') }}" required />
                            </div>
                            <x-admin-dashboard.button type="submit" variant="neutral" size="sm" class="w-full">Simpan address</x-admin-dashboard.button>
                        </form>

                        <div class="mt-4 space-y-3 border-t border-admin-line pt-4">
                            @forelse (($bsc['receivers'] ?? []) as $receiver)
                                <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-admin-ink">{{ $receiver['label'] }}</p>
                                            <p class="mt-1 break-all font-mono text-xs font-medium text-admin-muted">{{ $receiver['address'] }}</p>
                                        </div>
                                        <x-admin-dashboard.badge :tone="$receiver['is_active'] ? 'emerald' : 'amber'">
                                            {{ $receiver['is_active'] ? 'Aktif' : $receiver['verification_label'] }}
                                        </x-admin-dashboard.badge>
                                    </div>

                                    @if ($receiver['is_active'])
                                        <form method="POST" action="{{ $receiver['deactivate_url'] }}" class="mt-3">
                                            @csrf
                                            <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="w-full">Nonaktifkan receiver</x-admin-dashboard.button>
                                        </form>
                                    @elseif (in_array($receiver['verification_status'], ['verified', 'manual_verified'], true))
                                        <form method="POST" action="{{ $receiver['activate_url'] }}" class="mt-3">
                                            @csrf
                                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">Aktifkan receiver</x-admin-dashboard.button>
                                        </form>
                                    @elseif ($receiver['verification_status'] === 'pending_verification')
                                        <form method="POST" action="{{ $receiver['manual_verify_url'] }}" class="mt-3">
                                            @csrf
                                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">Aktifkan address</x-admin-dashboard.button>
                                        </form>
                                    @else
                                        <p class="mt-3 text-xs font-medium text-admin-muted">Address belum bisa dipakai. Tambahkan address baru.</p>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-admin-muted">Belum ada BSC Receiving Address.</p>
                            @endforelse
                        </div>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-4 text-xs">Hanya Owner yang dapat menambah, mengaktifkan, atau menonaktifkan destination address BSC.</x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>

    @endif
    @if ($settingsSection === 'saldo')
                <x-admin-dashboard.card
                    id="admin-store-balance-funding-policy"
                    data-admin-return-target
                    tabindex="-1"
                    as="section"
                    padding="compact"
                    shadow="sm"
                    aria-label="Kebijakan Top-up Saldo"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-admin-muted">Store Balance</p>
                            <h2 class="mt-1 text-lg font-semibold text-admin-ink">Top-up saldo rupiah</h2>
                        </div>
                    </div>

                    <p class="mt-2 text-sm leading-6 text-admin-muted">
                        Minimum saat ini:
                        <span class="font-semibold text-admin-ink">{{ $fundingPolicy['minimum_formatted'] ?? '—' }}</span>
                    </p>
                    <p class="mt-1 text-sm leading-6 text-admin-muted">Pilihan nominal: {{ implode(', ', $fundingPolicy['preset_amounts_formatted'] ?? []) }}.</p>
                    <details class="mt-3" @if ($errors->getBag('fundingPolicy')->any()) open @endif>
                        <summary class="min-h-11 cursor-pointer rounded-lg py-3 text-sm font-semibold text-admin-accent-strong focus-visible:outline-2 focus-visible:outline-admin-focus">Ubah nominal top-up</summary>

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
                            <label class="block text-xs font-medium text-admin-ink">
                                Minimum top-up (IDR)
                                <x-admin-dashboard.input class="mt-1" name="minimum_amount" inputmode="numeric" autocomplete="off" :value="old('minimum_amount', $fundingPolicy['minimum_amount'])" required />
                            </label>
                            <label class="block text-xs font-medium text-admin-ink">
                                Preset top-up (satu nominal per baris)
                                <textarea name="preset_amounts" rows="6" inputmode="numeric" class="mt-1 min-h-28 w-full rounded-lg border border-admin-line bg-admin-surface px-3 py-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" autocomplete="off" required>{{ is_array(old('preset_amounts')) ? implode("\n", old('preset_amounts')) : old('preset_amounts', $fundingPolicy['preset_amounts_input'] ?? '') }}</textarea>
                            </label>
                            <p class="text-xs font-medium leading-5 text-admin-muted">Gunakan angka rupiah utuh tanpa titik, koma, atau desimal. Maksimal {{ \App\Models\StoreBalanceFundingPolicy::MAX_PRESETS }} preset; semua preset harus unik, menaik, dan tidak kurang dari minimum.</p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">
                                Simpan kebijakan top-up
                            </x-admin-dashboard.button>
                        </form>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            Finance dan Support dapat melihat kebijakan, tetapi tidak dapat mengubah minimum atau preset top-up.
                        </x-admin-dashboard.notice>
                    @endif
                    </details>
                </x-admin-dashboard.card>

                @php($usdtFundingEnabledInput = old('funding_enabled', ($usdtFundingPolicy['funding_enabled'] ?? false) ? '1' : '0'))
                <x-admin-dashboard.card
                    id="admin-usdt-funding-policy"
                    data-admin-return-target
                    tabindex="-1"
                    as="section"
                    padding="compact"
                    shadow="sm"
                    aria-label="Kebijakan Top-up USDT"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-admin-muted">Customer USDT</p>
                            <h2 class="mt-1 text-lg font-semibold text-admin-ink">Top-up saldo USDT</h2>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @if ($usdtFundingPolicy['funding_enabled'] ?? false)
                                <x-admin-dashboard.badge tone="emerald">AKTIF</x-admin-dashboard.badge>
                            @else
                                <x-admin-dashboard.badge tone="amber">NONAKTIF</x-admin-dashboard.badge>
                            @endif
                        </div>
                    </div>

                    <p class="mt-2 text-sm leading-6 text-admin-muted">Metode yang tersedia mengikuti konfigurasi dan verifikasi di bawah. Mengubah pengaturan tidak memindahkan saldo pelanggan.</p>

                    <div class="mt-3 space-y-2">
                        @foreach (($usdtFundingPolicy['rails'] ?? []) as $rail)
                            <div class="flex items-start justify-between gap-2 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-admin-ink">{{ $rail['label'] }}</p>
                                    <p class="text-xs font-medium text-admin-muted">
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

                    <details class="mt-4" @if ($errors->getBag('usdtFundingPolicy')->has('reason')) open @endif>
                        <summary class="min-h-11 cursor-pointer rounded-lg py-3 text-sm font-semibold text-admin-accent-strong focus-visible:outline-2 focus-visible:outline-admin-focus">Jeda layanan saat ada masalah</summary>
                        <p class="text-sm leading-6 text-admin-muted">Jeda hanya bagian yang bermasalah. Pembayaran yang sudah masuk tetap direkonsiliasi.</p>
                    <div class="mt-3 space-y-2">
                        @foreach (($usdtFundingPolicy['pauses'] ?? []) as $pause)
                            <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-semibold text-admin-ink">{{ $pause['label'] }}</p>
                                    @if ($pause['paused'])
                                        <x-admin-dashboard.badge tone="rose">DIJEDA</x-admin-dashboard.badge>
                                    @else
                                        <x-admin-dashboard.badge tone="emerald">BERJALAN</x-admin-dashboard.badge>
                                    @endif
                                </div>
                                @if ($pause['paused'])
                                    <p class="mt-1 text-xs font-medium text-admin-muted">{{ $pause['reason'] }} &middot; {{ $pause['paused_at'] }}</p>
                                @endif
                                @if ($usdtFundingPolicy['can_manage'] ?? false)
                                    @if ($pause['paused'])
                                        <form method="POST" action="{{ route('admin.store-balance.usdt-pauses.destroy', $pause['scope']) }}" class="mt-2">
                                            @csrf
                                            @method('DELETE')
                                            <x-admin-dashboard.button type="submit" variant="secondary" size="sm" class="w-full">Lanjutkan</x-admin-dashboard.button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.store-balance.usdt-pauses.store', $pause['scope']) }}" class="mt-2 space-y-2">
                                            @csrf
                                            <x-admin-dashboard.input name="reason" :value="old('reason')" placeholder="Alasan jeda (wajib)" required />
                                            <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="w-full">Jeda</x-admin-dashboard.button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    </details>
                    <details class="mt-3" @if ($errors->getBag('usdtFundingPolicy')->any()) open @endif>
                        <summary class="min-h-11 cursor-pointer rounded-lg py-3 text-sm font-semibold text-admin-accent-strong focus-visible:outline-2 focus-visible:outline-admin-focus">Ubah status & pengaturan lanjutan</summary>

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
                            <label class="block text-xs font-medium text-admin-ink">
                                Status top-up USDT
                                <x-admin-dashboard.select class="mt-1" name="funding_enabled" required>
                                    <option value="0" @selected((string) $usdtFundingEnabledInput !== '1')>Nonaktif (aktifkan hanya setelah rail qualified)</option>
                                    <option value="1" @selected((string) $usdtFundingEnabledInput === '1')>Aktif</option>
                                </x-admin-dashboard.select>
                            </label>
                            <label class="block text-xs font-medium text-admin-ink">
                                Masa konfirmasi (detik, 60-3600)
                                <x-admin-dashboard.input class="mt-1" name="confirmation_ttl_seconds" inputmode="numeric" autocomplete="off" :value="old('confirmation_ttl_seconds', $usdtFundingPolicy['confirmation_ttl_seconds'] ?? 600)" required />
                            </label>
                            <label class="block text-xs font-medium text-admin-ink">
                                Fee aplikasi (USDT, maksimal 6 desimal)
                                <x-admin-dashboard.input class="mt-1" name="application_fee_micro" inputmode="decimal" autocomplete="off" :value="old('application_fee_micro', $usdtFundingPolicy['application_fee_input'] ?? '0')" required />
                            </label>
                            <label class="block text-xs font-medium text-admin-ink">
                                Syarat yang ditampilkan kepada pelanggan
                                <x-admin-dashboard.textarea class="mt-1" name="terms_body" :rows="6" :value="old('terms_body', $usdtFundingPolicy['terms_body'] ?? '')" required />
                            </label>
                            <p class="text-xs font-medium leading-5 text-admin-muted">Tidak ada minimum, maksimum, atau pilihan nominal tetap untuk USDT. Syarat yang disetujui pelanggan tetap tersimpan pada transaksi lama.</p>
                            <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">
                                Simpan kebijakan top-up USDT
                            </x-admin-dashboard.button>
                        <a href="{{ route('admin.store-balance.usdt-review.index') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-admin-accent-strong underline-offset-4 hover:underline">Review deposit USDT dan insiden</a>
                        </form>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">
                            Hanya Owner yang dapat mengubah kebijakan dan jeda top-up USDT.
                        </x-admin-dashboard.notice>
                    @endif
                    </details>
                </x-admin-dashboard.card>

    @endif
    @if ($settingsSection === 'diagnostik')
                <x-admin-dashboard.card
                    id="admin-balance-operations"
                    as="section"
                    padding="compact"
                    shadow="sm"
                    aria-label="Kontrol Operasional Saldo"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-admin-muted">Operasional · read-only</p>
                            <h2 class="mt-1 text-lg font-semibold text-admin-ink">Kontrol Operasional</h2>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @if ($balanceOperations['restoreGate']['open'] ?? false)
                                <x-admin-dashboard.badge tone="rose" dot>GATE TERBUKA</x-admin-dashboard.badge>
                            @else
                                <x-admin-dashboard.badge tone="emerald" dot>GATE TERTUTUP</x-admin-dashboard.badge>
                            @endif
                            @if (($balanceOperations['alertCounts']['critical'] ?? 0) > 0)
                                <x-admin-dashboard.badge tone="rose">{{ $balanceOperations['alertCounts']['critical'] }} KRITIS</x-admin-dashboard.badge>
                            @endif
                        </div>
                    </div>

                    <p class="mt-2 text-sm leading-6 text-admin-muted">
                        Status jeda, gerbang pemulihan, dan episode alert saldo. Hanya tampilan; tidak ada aksi tulis di sini.
                        {{ $balanceOperations['alertCounts']['open'] ?? 0 }} episode terbuka
                        ({{ $balanceOperations['alertCounts']['critical'] ?? 0 }} kritis, {{ $balanceOperations['alertCounts']['warning'] ?? 0 }} peringatan).
                    </p>

                    <div class="mt-3 space-y-2" aria-label="Jeda operasional">
                        @foreach (($balanceOperations['pauses'] ?? []) as $opsPause)
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-admin-ink">{{ $opsPause['label'] }}</p>
                                    @if ($opsPause['paused'])
                                        <p class="text-xs font-medium text-admin-muted">{{ $opsPause['reason'] }} &middot; {{ $opsPause['paused_at'] }}</p>
                                    @endif
                                </div>
                                @if ($opsPause['paused'])
                                    <x-admin-dashboard.badge tone="rose">DIJEDA</x-admin-dashboard.badge>
                                @else
                                    <x-admin-dashboard.badge tone="emerald">BERJALAN</x-admin-dashboard.badge>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-admin-ink">Gerbang pemulihan saldo</p>
                            @if ($balanceOperations['restoreGate']['open'] ?? false)
                                <x-admin-dashboard.badge tone="rose">TERBUKA</x-admin-dashboard.badge>
                            @else
                                <x-admin-dashboard.badge tone="emerald">TERTUTUP</x-admin-dashboard.badge>
                            @endif
                        </div>
                        <p class="mt-1 text-xs font-medium leading-5 text-admin-muted">
                            Window {{ $balanceOperations['restoreGate']['window_start'] ?? '—' }} s.d. {{ $balanceOperations['restoreGate']['window_end'] ?? '—' }}
                            &middot; dibuka {{ $balanceOperations['restoreGate']['opened_at'] ?? '—' }}
                            &middot; replay {{ $balanceOperations['restoreGate']['replay_completed_at'] ?? 'belum selesai' }}
                        </p>
                    </div>

                    <div class="mt-3 space-y-2" aria-label="Episode alert terbuka">
                        <p class="text-xs font-medium text-admin-muted">Episode alert terbuka</p>
                        @forelse (($balanceOperations['alerts'] ?? []) as $opsAlert)
                            <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="break-words text-sm font-semibold text-admin-ink">{{ $opsAlert['summary'] }}</p>
                                    @if ($opsAlert['severity'] === 'critical')
                                        <x-admin-dashboard.badge tone="rose">{{ $opsAlert['severity_label'] }}</x-admin-dashboard.badge>
                                    @else
                                        <x-admin-dashboard.badge tone="amber">{{ $opsAlert['severity_label'] }}</x-admin-dashboard.badge>
                                    @endif
                                </div>
                                <p class="mt-1 break-all font-mono text-xs font-medium text-admin-muted">
                                    {{ $opsAlert['alert_key'] }} &middot; {{ $opsAlert['state_label'] }} &middot; {{ $opsAlert['occurrences'] }}x &middot; terakhir {{ $opsAlert['last_seen_at'] }}
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-admin-muted">Tidak ada episode alert terbuka.</p>
                        @endforelse
                    </div>

                    @if (! empty($balanceOperations['metrics']))
                        <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                            <p class="text-xs font-medium text-admin-muted">Metrik operasi (60 menit)</p>
                            <div class="mt-2 space-y-2">
                                @foreach ($balanceOperations['metrics'] as $opsMetricKey => $opsMetricValue)
                                    @if (is_array($opsMetricValue))
                                        <div class="rounded-lg border border-admin-line bg-admin-surface p-3">
                                            <p class="break-all font-mono text-xs font-medium text-admin-muted">{{ $opsMetricKey }}</p>
                                            <dl class="mt-1 grid gap-x-3 gap-y-1 sm:grid-cols-2">
                                                @foreach ($opsMetricValue as $opsSubKey => $opsSubValue)
                                                    <div class="flex items-baseline justify-between gap-2">
                                                        <dt class="break-all font-mono text-xs font-medium text-admin-muted">{{ $opsSubKey }}</dt>
                                                        <dd class="break-all text-sm font-semibold text-admin-ink">{{ is_array($opsSubValue) ? ($opsSubValue['count'] ?? '—') : (is_bool($opsSubValue) ? ($opsSubValue ? 'ya' : 'tidak') : ($opsSubValue ?? '—')) }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </div>
                                    @else
                                        <div class="flex items-baseline justify-between gap-2 rounded-lg border border-admin-line bg-admin-surface p-3">
                                            <span class="break-all font-mono text-xs font-medium text-admin-muted">{{ $opsMetricKey }}</span>
                                            <span class="break-all text-sm font-semibold text-admin-ink">{{ is_bool($opsMetricValue) ? ($opsMetricValue ? 'ya' : 'tidak') : ($opsMetricValue ?? '—') }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-admin-dashboard.card>


    @endif
    @if ($settingsSection === 'pembayaran')
                <x-admin-dashboard.card
                    id="admin-checkout-maintenance"
                    data-admin-return-target
                    tabindex="-1"
                    padding="compact"
                    shadow="sm"
                    aria-label="Checkout Maintenance"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-admin-muted">Checkout Maintenance</p>
                            <h2 class="mt-1 text-lg font-semibold text-admin-ink">{{ $checkoutMaintenance['label'] }}</h2>
                        </div>
                        <x-admin-dashboard.badge :tone="$checkoutMaintenance['tone']" dot>
                            {{ $checkoutMaintenance['status_badge']['label'] }}
                        </x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-sm leading-6 text-admin-muted">{{ $checkoutMaintenance['hint'] }}</p>
                    @if ($checkoutMaintenance['activated_at'])
                        <p class="mt-2 text-xs font-medium text-admin-ink">Aktif sejak {{ $checkoutMaintenance['activated_at'] }}</p>
                    @endif
                    @if ($checkoutMaintenance['public_note'])
                        <div class="mt-3 rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                            <p class="text-xs font-medium text-admin-muted">Catatan publik</p>
                            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-admin-ink">{{ $checkoutMaintenance['public_note'] }}</p>
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
                                <label class="block text-xs font-medium text-admin-ink">
                                    Catatan publik (opsional)
                                    <textarea name="public_note" maxlength="500" rows="3" class="mt-1 min-h-24 w-full rounded-lg border border-admin-line bg-admin-surface px-3 py-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" placeholder="Contoh: Checkout dibuka kembali pukul 14.00.">{{ old('public_note', $checkoutMaintenance['public_note']) }}</textarea>
                                </label>
                                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">
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
                                <x-admin-dashboard.button type="submit" variant="warning" size="sm" class="w-full">
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
                                <label class="block text-xs font-medium text-admin-ink">
                                    Catatan publik (opsional)
                                    <textarea name="public_note" maxlength="500" rows="3" class="mt-1 min-h-24 w-full rounded-lg border border-admin-line bg-admin-surface px-3 py-3 text-sm font-normal text-admin-ink transition-colors placeholder:text-admin-muted focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper" placeholder="Contoh: Checkout dibuka kembali pukul 14.00.">{{ old('public_note', $checkoutMaintenance['public_note']) }}</textarea>
                                </label>
                                <x-admin-dashboard.button type="submit" variant="primary" size="sm" class="w-full">
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
                            class="relative max-h-[calc(100dvh-1.5rem)] w-full max-w-lg overflow-y-auto rounded-[var(--admin-radius)] border border-admin-line bg-admin-surface p-4 text-admin-ink shadow-admin-lg sm:p-5"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 pr-8">
                                    <p class="text-xs font-medium text-admin-muted">Checkout Maintenance</p>
                                    <h2 id="admin-checkout-maintenance-title" class="mt-1 text-lg font-semibold leading-tight text-admin-ink">Konfirmasi perubahan Checkout Maintenance</h2>
                                </div>
                                <button type="button" class="absolute right-3 top-3 inline-flex h-11 w-11 items-center justify-center rounded-lg border border-admin-line bg-admin-surface text-lg text-admin-ink focus-visible:outline-2 focus-visible:outline-admin-focus" aria-label="Tutup dialog Checkout Maintenance" x-on:click="closeCheckoutMaintenanceConfirmation()">×</button>
                            </div>
                            <div id="admin-checkout-maintenance-description" class="mt-4 space-y-3 text-sm font-normal leading-6 text-admin-ink">
                                <p x-text="checkoutMaintenanceConfirmationText"></p>
                                <p class="rounded-lg border border-admin-line bg-admin-surface-muted p-3 text-xs font-medium leading-5 text-admin-muted">
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
    @endif
            </div>
