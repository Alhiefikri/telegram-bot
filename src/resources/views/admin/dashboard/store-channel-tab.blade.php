            <div x-show="activeTab === 'telegram-channel'" class="mt-4 space-y-4">
                <x-admin-dashboard.card as="section" tone="purple" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Telegram Channel">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider text-brand-muted">Telegram Channel</p>
                            <h2 class="mt-1 font-display text-base font-bold text-neo-ink">Setup Store Channel</h2>
                        </div>
                        <x-admin-dashboard.badge :tone="$storeChannel['status']['tone']">{{ $storeChannel['status']['label'] }}</x-admin-dashboard.badge>
                    </div>
                    <p class="mt-2 text-xs font-semibold text-brand-muted">{{ $storeChannel['status']['hint'] }}</p>

                    @error('channel_username', 'storeChannel')<x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>@enderror
                    @error('channel_link', 'storeChannel')<x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>@enderror

                    <form method="POST" action="{{ $storeChannel['update_url'] }}" class="mt-4 space-y-3">
                        @csrf
                        <x-admin-dashboard.field-label for="store-channel-username">Username channel (@channel)</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="store-channel-username" name="channel_username" value="{{ old('channel_username', $storeChannel['setting']->channel_username) }}" placeholder="@lhie_store" />
                        <x-admin-dashboard.field-label for="store-channel-link">Link channel publik</x-admin-dashboard.field-label>
                        <x-admin-dashboard.input id="store-channel-link" name="channel_link" value="{{ old('channel_link', $storeChannel['setting']->channel_link) }}" placeholder="https://t.me/lhie_store" />
                        <x-admin-dashboard.telegram-status :details="$storeChannel['details']" />
                        <x-admin-dashboard.checkbox name="community_gate_enabled" :checked="old('community_gate_enabled', $storeChannel['setting']->community_gate_enabled)">
                            Community Gate
                        </x-admin-dashboard.checkbox>
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
                        <form method="POST" action="{{ $storeChannel['test_url'] }}" class="mt-3">
                            @csrf
                            <x-admin-dashboard.button variant="purple" class="w-full" type="submit">Test Channel</x-admin-dashboard.button>
                        </form>
                    @endif
                </x-admin-dashboard.card>

                @php($fakeSalesProof = $storeChannel['fake_sales_proof'])
                <x-admin-dashboard.card as="section" tone="purple" padding="compact" shadow="sm" class="rounded-[1.125rem]" aria-label="Info pembelian fake">
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
                        Pilih produk nyata lalu kirim format “PEMBELIAN SUKSES” ke channel memakai nama random, tanpa membuat data Pesanan dan tanpa mengurangi stok.
                    </p>

                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <x-admin-dashboard.mini-stat label="Interval" :value="$fakeSalesProof['interval_label']" align="left" />
                        <x-admin-dashboard.mini-stat label="Terakhir" :value="$fakeSalesProof['last_sent_label']" align="left" />
                        <x-admin-dashboard.mini-stat label="Kirim lagi" :value="$fakeSalesProof['next_available_label']" align="left" />
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
                            Guard aktif: setelah sukses, cooldown dipilih acak {{ $fakeSalesProof['interval_options_label'] }}. Stok produk tidak disentuh.
                        </p>
                    @else
                        <x-admin-dashboard.notice tone="neutral" class="mt-3 text-xs">Hanya owner/admin yang bisa mengirim info pembelian fake.</x-admin-dashboard.notice>
                    @endif
                </x-admin-dashboard.card>
            </div>
