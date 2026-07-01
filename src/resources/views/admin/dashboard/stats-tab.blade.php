            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  STATS TAB (Ringkasan)                              ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-show="activeTab === 'stats'" class="mt-4 space-y-4">

                {{-- Link Toko section --}}
                <section class="store-link-section overflow-hidden rounded-[1.5rem] p-4 text-neo-ink" style="border: 3px solid #050505; box-shadow: 4px 4px 0px #050505;" aria-label="Link toko">
                    <p class="font-display text-xs font-black uppercase tracking-[0.18em] text-neo-ink/80">{{ $storeLink['label'] }}</p>
                    <div class="mt-2 overflow-hidden rounded-[1rem] bg-white px-3 py-2.5" style="border: 3px solid #050505;">
                        <p class="break-all text-sm font-bold leading-6" style="color: #050505;">{{ $storeLink['url'] }}</p>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="neo-btn neo-btn-white text-[11px]"
                            style="box-shadow: 2px 2px 0px rgba(0,0,0,0.35);"
                            x-on:click="shareStore()"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="2"/></svg>
                            Bagikan
                        </button>
                        <button
                            type="button"
                            class="neo-btn neo-btn-ink text-[11px]"
                            style="box-shadow: 2px 2px 0px rgba(0,0,0,0.35);"
                            x-on:click="copyStoreLink()"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" stroke-width="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke="currentColor" stroke-width="2"/></svg>
                            <span x-text="copyState">Salin</span>
                        </button>
                    </div>
                </section>

                {{-- STATISTIK heading --}}
                <div>
                    <h2 class="font-display text-lg font-black uppercase tracking-[0.18em]" style="color: #050505;">📊 Ringkasan</h2>
                    <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Gambaran cepat operasional hari ini</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    {{-- Stats cards grid --}}
                    <section class="grid grid-cols-2 gap-3" aria-label="Kartu Stats">
                        @foreach ($statsCards as $metric)
                            <div class="{{ $metric['card_class'] }} rounded-xl p-3" style="border: 2px solid #050505; box-shadow: 2px 2px 0px #050505;">
                                <p class="font-display text-[10px] font-bold uppercase tracking-wider" style="color: #050505;">{{ $metric['label'] }}</p>
                                <p class="mt-1 break-words font-display text-xl font-bold" style="color: #050505;">{{ $metric['value'] }}</p>
                                @if ($metric['hint'])
                                    <p class="mt-0.5 text-[10px] font-semibold opacity-60" style="color: #050505;">{{ $metric['hint'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </section>

                    {{-- STATUS ORDER --}}
                    <section aria-label="Status order">
                        <h2 class="font-display text-sm font-black uppercase tracking-[0.18em]" style="color: #050505;">📦 Status Pesanan</h2>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            @foreach ($actionableStatusCounts as $status)
                                <div class="status-order-card {{ $status['card_class'] }}" @if($status['inline_style']) style="{{ $status['inline_style'] }}" @endif>
                                    <p class="truncate font-display text-[10px] font-bold uppercase tracking-wider" style="color: #050505;">{{ $status['label'] }}</p>
                                    <p class="mt-1 font-display text-2xl font-bold" style="color: #050505;">{{ $status['count'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                </div>

                {{-- Pendapatan 7 hari --}}
                <section aria-label="Pendapatan 7 hari">
                    <h2 class="font-display text-sm font-bold uppercase tracking-wider" style="color: #050505;">💰 Pendapatan 7 hari</h2>
                    <p class="mt-1 text-[11px] font-semibold" style="color: #64748b;">Pembayaran lunas</p>
                    <div class="mt-3 space-y-3">
                        @foreach ($earnings as $earning)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-12 shrink-0 font-display text-xs font-bold" style="color: #64748b;">{{ $earning['label'] }}</span>
                                <div class="earnings-bar-track flex-1">
                                    <div class="earnings-bar-fill" style="width: {{ $earning['bar_percent'] }}%"></div>
                                </div>
                                <span class="w-24 shrink-0 text-right font-display text-xs font-bold" style="color: #050505;">{{ $earning['amount_formatted'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Payment control (merged from sidebar) --}}
                <section class="payment-section" aria-label="Mode pembayaran">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider" style="color: #64748b;">Mode pembayaran</p>
                            <h2 class="mt-1 font-display text-base font-bold" style="color: #050505;">{{ $paymentControl['label'] }}</h2>
                        </div>
                        <span class="neo-badge" style="background: {{ $paymentControl['status_badge']['background'] }}; color: #050505;">
                            <span class="h-1.5 w-1.5 rounded-full" style="background: {{ $paymentControl['status_badge']['dot'] }};"></span>
                            {{ $paymentControl['status_badge']['label'] }}
                        </span>
                    </div>

                    <p class="mt-2 text-xs font-semibold" style="color: #64748b;">
                        {{ $paymentControl['hint'] }}
                    </p>

                    @error('payment_mode', 'paymentMode')
                        <p class="mt-3 rounded-lg p-2 text-xs font-bold text-white" style="background: #ef4444; border: 2px solid #050505;">{{ $message }}</p>
                    @enderror

                    @if ($paymentControl['can_manage'])
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <form method="POST" action="{{ $paymentControl['update_url'] }}">
                                @csrf
                                <input type="hidden" name="payment_mode" value="manual">
                                <button
                                    type="submit"
                                    class="product-action-btn w-full text-xs {{ $paymentControl['mode'] === 'manual' ? 'neo-btn-yellow' : '' }}"
                                    style="{{ $paymentControl['mode'] !== 'manual' ? 'background: #fff;' : '' }}"
                                >
                                    QR Manual /payment
                                </button>
                            </form>

                            <form method="POST" action="{{ $paymentControl['update_url'] }}">
                                @csrf
                                <input type="hidden" name="payment_mode" value="pakasir">
                                <button
                                    type="submit"
                                    class="product-action-btn w-full text-xs {{ $paymentControl['mode'] === 'pakasir' ? 'neo-btn-purple' : '' }}"
                                    style="{{ $paymentControl['mode'] !== 'pakasir' ? 'background: #fff;' : '' }}"
                                >
                                    Pakasir
                                </button>
                            </form>
                        </div>
                    @else
                        <p class="mt-3 rounded-lg p-2 text-xs font-semibold" style="background: #fff; border: 2px solid #050505; color: #64748b;">
                            Hanya owner/admin yang bisa mengubah mode pembayaran.
                        </p>
                    @endif
                </section>
            </div>
