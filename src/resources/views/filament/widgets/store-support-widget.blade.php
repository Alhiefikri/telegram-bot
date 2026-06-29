<x-filament-widgets::widget>
<x-lhie.dashboard-card>
    <x-lhie.section-header eyebrow="Akses toko" title="Aksi cepat admin">
        <x-slot:aside>
            <x-lhie.status-pill :label="$support['status_label']" :tone="$support['status_tone']" />
        </x-slot:aside>
    </x-lhie.section-header>

    <div class="mt-5 space-y-4">
        <div>
            <div class="lhie-action-grid">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}" class="lhie-action-card">
                        <div class="lhie-icon-tile" data-tone="slate">
                            <x-lhie.icon :name="$action['icon']" class="h-4 w-4" />
                        </div>

                        <div class="min-w-0">
                            <div class="lhie-action-card__label">{{ $action['label'] }}</div>
                            <div class="lhie-action-card__description">{{ $action['description'] }}</div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="lhie-support-panel">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <span class="lhie-terminal-badge">Active terminal</span>
                    <h3 class="lhie-support-title">Support Telegram</h3>
                </div>
                <span class="lhie-support-online-dot"></span>
            </div>

            <div class="mt-4 space-y-3">
                <div class="lhie-support-meta-box">
                    <div class="lhie-support-row">
                        <span class="lhie-support-label">Mode pembayaran:</span>
                        <span class="lhie-support-value">{{ ucfirst((string) $support['payment_mode']) }}</span>
                    </div>

                    <div class="lhie-support-row">
                        <span class="lhie-support-label">Kontak admin:</span>
                        @if ($support['contact_url'])
                            <a href="{{ $support['contact_url'] }}" target="_blank" rel="noreferrer" class="lhie-support-value text-brand-navy hover:text-brand-electric">
                                {{ $support['contact_url'] }}
                            </a>
                        @elseif ($support['username'])
                            <span class="lhie-support-value">{{ '@'.$support['username'] }}</span>
                        @elseif ($support['chat_id'])
                            <span class="lhie-support-value">Chat ID: {{ $support['chat_id'] }}</span>
                        @else
                            <span class="lhie-support-value text-brand-muted">Belum diisi</span>
                        @endif
                    </div>
                </div>

                @if ($support['contact_url'])
                    <a href="{{ $support['contact_url'] }}" target="_blank" rel="noreferrer" class="lhie-telegram-button">
                        <x-lhie.icon name="support" class="h-4 w-4" />
                        <span>Buka Telegram Support</span>
                    </a>
                @endif
            </div>
        </div>

        @if (! $setting)
            <div class="lhie-empty-state">
                <div class="lhie-empty-state__icon">
                    <x-lhie.icon name="support" class="h-5 w-5" />
                </div>

                <div class="space-y-1">
                    <p class="lhie-empty-state__title">Pengaturan pembayaran belum dibuat</p>
                    <p class="lhie-empty-state__description">Buat pengaturan supaya kontak admin, mode pembayaran, dan instruksi tampil rapi di dashboard.</p>
                </div>
            </div>
        @endif
    </div>
</x-lhie.dashboard-card>
</x-filament-widgets::widget>
