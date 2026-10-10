            <div id="admin-section-store-channel" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">
                @php($welcomeBanner = $storeChannel['welcome_banner'])
                <x-admin-dashboard.card id="admin-section-telegram-welcome-banner" data-admin-return-target tabindex="-1" as="section" tone="green" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-labelledby="admin-telegram-welcome-banner-title">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Banner sambutan bot Telegram</p>
                            <h2 id="admin-telegram-welcome-banner-title" class="mt-1 font-display text-base font-bold text-neo-ink">Satu banner aktif untuk /start</h2>
                            <p class="mt-2 max-w-2xl text-xs font-semibold leading-5 text-brand-muted">Hanya untuk sambutan /start di bot, bukan detail produk atau poster stok Store Channel. Upload tanpa rebuild Docker; file di persistent storage dan file_id di-cache Telegram.</p>
                        </div>
                        <div class="flex h-28 w-44 shrink-0 items-center justify-center overflow-hidden rounded-xl border-[3px] border-neo-ink bg-white shadow-[3px_3px_0_#050505]">
                            @if ($welcomeBanner['is_custom'])
                                <img src="{{ $welcomeBanner['image_url'] }}" alt="Preview Telegram Welcome Banner aktif" class="h-full w-full object-contain" loading="lazy" decoding="async">
                            @else
                                <span class="px-3 text-center text-[10px] font-black uppercase leading-4 text-neo-ink/60">Fallback bawaan atau teks aktif</span>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-[10px] font-black uppercase tracking-wide">
                        <x-admin-dashboard.badge :tone="$welcomeBanner['is_custom'] ? 'emerald' : 'amber'">
                            {{ $welcomeBanner['is_custom'] ? 'Custom aktif · v'.$welcomeBanner['version'] : 'Fallback aktif' }}
                        </x-admin-dashboard.badge>
                        <span class="rounded-full border-2 border-neo-ink bg-white px-2 py-1">
                            {{ $welcomeBanner['file_id_cached'] ? 'file_id Telegram siap' : 'file_id di-cache saat pengiriman pertama' }}
                        </span>
                    </div>

                    @if ($welcomeBanner['can_manage'])
                        <form method="POST" action="{{ $welcomeBanner['update_url'] }}" enctype="multipart/form-data" x-data="{ fileName: 'Belum ada file yang dipilih' }" class="mt-4 flex flex-col gap-3 rounded-xl border-2 border-dashed border-neo-ink/40 bg-white/60 p-3 sm:flex-row sm:items-end">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <x-admin-dashboard.field-label for="telegram-welcome-banner-image">Gambar banner /start</x-admin-dashboard.field-label>
                                <div class="mt-2 flex min-w-0 items-center gap-3 rounded-lg border-2 border-neo-ink bg-white p-2 shadow-[2px_2px_0_#050505]">
                                    <label for="telegram-welcome-banner-image" class="shrink-0 cursor-pointer rounded-md bg-neo-ink px-3 py-2 text-xs font-black text-white transition hover:bg-[#8758de]">Pilih banner /start</label>
                                    <span class="min-w-0 flex-1 truncate text-xs font-black text-neo-ink" x-text="fileName">Belum ada file yang dipilih</span>
                                </div>
                                <input type="file" id="telegram-welcome-banner-image" name="image" accept="image/jpeg,image/png,image/webp" required class="sr-only" x-on:change="fileName = $event.target.files[0]?.name ?? 'Belum ada file yang dipilih'">
                                <p class="mt-2 text-[10px] font-bold text-neo-ink/60">JPG, PNG, atau WebP. Maksimal 5 MB; otomatis dikompresi menjadi WebP maksimal 1600px.</p>
                            </div>
                            <x-admin-dashboard.button type="submit" variant="purple" size="sm" class="w-full shrink-0 sm:w-auto">Simpan banner</x-admin-dashboard.button>
                        </form>
                        @error('image', 'welcomeBanner')
                            <x-admin-dashboard.field-error class="mt-2">{{ $message }}</x-admin-dashboard.field-error>
                        @enderror
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-4 text-xs">Role ini hanya bisa melihat banner. Owner/Admin dapat menggantinya dari sini.</x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>
                <x-admin-dashboard.card as="section" tone="purple" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Telegram Channel">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Telegram Channel</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">Setup Store Channel</h2>
                        </div>
                        <x-admin-dashboard.badge :tone="$storeChannel['status']['tone']">{{ $storeChannel['status']['label'] }}</x-admin-dashboard.badge>
                    </div>
                    <p class="mt-2 text-xs font-semibold text-brand-muted">{{ $storeChannel['status']['hint'] }}</p>
                    <p class="mt-2 text-xs font-semibold text-brand-muted">Poster produk untuk posting stok bertambah diatur per produk pada tab Produk → Edit produk → Poster stok bertambah. Tidak dipakai untuk sales proof, banner /start, atau pesan pelanggan.</p>

                    @error('channel_username', 'storeChannel')<x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>@enderror
                    @error('channel_link', 'storeChannel')<x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>@enderror

                    <form method="POST" action="{{ $storeChannel['update_url'] }}" class="mt-4 space-y-3">
                        @csrf
                        <x-admin-dashboard.field-label for="store-channel-username">Username channel (@channel)</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="store-channel-username" name="channel_username" value="{{ old('channel_username', $storeChannel['setting']->channel_username) }}" placeholder="@lhie_store" />
                        <x-admin-dashboard.field-label for="store-channel-link">Link channel publik</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="store-channel-link" name="channel_link" value="{{ old('channel_link', $storeChannel['setting']->channel_link) }}" placeholder="https://t.me/lhie_store" />
                        <x-admin-dashboard.telegram-status :details="$storeChannel['details']" />
                        <x-admin-dashboard.checkbox name="sales_proof_enabled" :checked="old('sales_proof_enabled', $storeChannel['setting']->sales_proof_enabled)">
                            Sales Proof Post
                        </x-admin-dashboard.checkbox>
                        @if($storeChannel['show_setup_warning'])
                            <x-admin-dashboard.notice tone="warning" class="text-xs">Warning: toggle aktif tetapi setup channel belum ready.</x-admin-dashboard.notice>
                        @endif
                        @if($storeChannel['can_manage'])
                            <x-admin-dashboard.button variant="warning" class="w-full" type="submit">Simpan Pengaturan</x-admin-dashboard.button>
                        @else
                            <x-admin-dashboard.notice tone="neutral" class="text-xs">Hanya owner/admin yang bisa mengubah Telegram Channel.</x-admin-dashboard.notice>
                        @endif
                    </form>
                    @if($storeChannel['can_manage'])
                        <form id="admin-store-channel-test" data-admin-return-target tabindex="-1" method="POST" action="{{ $storeChannel['test_url'] }}" class="mt-3">
                            @csrf
                            <x-admin-dashboard.button variant="purple" class="w-full" type="submit">Test Channel</x-admin-dashboard.button>
                        </form>
                    @endif
                </x-admin-dashboard.card>

                @php($fakeSalesProof = $storeChannel['fake_sales_proof'])
                <x-admin-dashboard.card id="admin-store-channel-fake-sales-proof" data-admin-return-target tabindex="-1" as="section" tone="purple" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Info pembelian fake">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Fake Sales Proof</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">Info Pembelian Fake</h2>
                        </div>
                        <x-admin-dashboard.badge :tone="$fakeSalesProof['can_send'] ? 'emerald' : 'amber'">
                            {{ $fakeSalesProof['can_send'] ? 'Ready' : 'Standby' }}
                        </x-admin-dashboard.badge>
                    </div>

                    <p class="mt-2 text-xs font-semibold text-brand-muted">
                        Pilih produk nyata lalu kirim manual kapan saja. Auto fake notif tetap jalan sendiri memakai interval random, tanpa membuat data Pesanan dan tanpa mengurangi stok.
                    </p>

                    <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                        <x-admin-dashboard.mini-stat label="Manual" value="Kapan saja" align="left" />
                        <x-admin-dashboard.mini-stat label="Manual terakhir" :value="$fakeSalesProof['manual_last_sent_label']" align="left" />
                        <x-admin-dashboard.mini-stat label="Auto interval" :value="$fakeSalesProof['interval_label']" align="left" />
                        <x-admin-dashboard.mini-stat label="Auto berikutnya" :value="$fakeSalesProof['next_available_label']" align="left" />
                    </div>

                    @if ($fakeSalesProof['disabled_reason'])
                        <x-admin-dashboard.notice tone="warning" class="mt-3 text-xs">{{ $fakeSalesProof['disabled_reason'] }}</x-admin-dashboard.notice>
                    @endif

                    @if($storeChannel['can_manage'])
                        <form method="POST" action="{{ $fakeSalesProof['send_url'] }}" class="mt-3">
                            @csrf
                            <x-admin-dashboard.field-label for="fake-sales-proof-product" class="text-xs">Pilih produk untuk notif fake</x-admin-dashboard.field-label>
                            <x-admin-dashboard.select
                                id="fake-sales-proof-product"
                                name="product_id"
                                class="mt-2"
                                :disabled="! $fakeSalesProof['can_send']"
                            >
                                @foreach ($fakeSalesProof['product_options'] as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-admin-dashboard.select>
                            <x-admin-dashboard.button
                                variant="warning"
                                class="w-full text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                type="submit"
                                :disabled="! $fakeSalesProof['can_send']"
                            >
                                🎉 Kirim Info Pembelian Fake
                            </x-admin-dashboard.button>
                        </form>
                        <p class="mt-2 text-[11px] font-semibold text-brand-muted">
                            Manual tidak kena cooldown. Auto dikirim scheduler saat interval acak {{ $fakeSalesProof['interval_options_label'] }} sudah due.
                        </p>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">Hanya owner/admin yang bisa mengirim info pembelian fake.</x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>
            </div>
