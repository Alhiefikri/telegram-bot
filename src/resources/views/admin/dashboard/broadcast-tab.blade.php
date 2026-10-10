            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  BROADCAST TAB                                      ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div id="admin-section-broadcast" data-admin-return-target tabindex="-1" class="mt-4 space-y-4">

                <div>
                    <h2 class="font-display text-lg font-black uppercase tracking-[0.18em] text-neo-ink">📢 Siaran</h2>
                    <p class="mt-1 text-xs font-semibold text-brand-muted">Kirim info ke pengguna aktif lewat bot</p>
                </div>

                @if ($errors->broadcast->any())
                    <x-admin-dashboard.notice tone="danger" class="animate-slide-up">
                        Periksa kembali pesan broadcast.
                    </x-admin-dashboard.notice>
                @endif

                <form method="POST" action="{{ route('admin.broadcast.store') }}" class="space-y-4">
                    @csrf

                    <x-admin-dashboard.notice tone="warning" class="rounded-xl p-4">
                        <p class="text-xs font-bold text-neo-ink">
                            ⚠️ Jangan isi data akun, password, kode OTP, atau secret pembeli di broadcast. Data akun hanya dikirim otomatis ke pembeli setelah pembayaran lunas.
                        </p>
                    </x-admin-dashboard.notice>

                    <div>
                        <x-admin-dashboard.field-label for="broadcast-message" class="text-xs">Pesan</x-admin-dashboard.field-label>
                        <x-admin-dashboard.textarea
                            id="broadcast-message"
                            name="message"
                            rows="7"
                            placeholder="Contoh: Stok Canva Pro sudah ready. Silakan cek /katalog."
                            class="mt-2"
                        >{{ old('message') }}</x-admin-dashboard.textarea>
                        @error('message', 'broadcast')
                            <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                        @enderror
                    </div>

                    <x-admin-dashboard.button type="submit" variant="purple" class="w-full text-sm">
                        📤 Kirim Siaran
                    </x-admin-dashboard.button>
                </form>

                @php($recentBroadcasts = $broadcastTab['recent'] ?? collect())
                <div class="rounded-2xl border-2 border-neo-ink bg-white p-4 shadow-neo">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-display text-sm font-black uppercase tracking-[0.16em] text-neo-ink">Status Siaran</h3>
                            <p class="mt-1 text-[11px] font-semibold text-brand-muted">Progres pengiriman terbaru. Isi pesan tidak ditampilkan di log operasional.</p>
                        </div>
                    </div>

                    <div class="mt-3 space-y-2">
                        @forelse ($recentBroadcasts as $broadcast)
                            <div class="rounded-xl border border-neo-ink/10 bg-brand-surface p-3 text-xs font-semibold text-neo-ink">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-black uppercase tracking-[0.12em]">#{{ $broadcast->id }} · {{ str_replace('_', ' ', $broadcast->status) }}</span>
                                    <span class="text-brand-muted">{{ $broadcast->created_at?->format('Y-m-d H:i') }}</span>
                                </div>
                                <div class="mt-2 grid grid-cols-4 gap-2 text-center">
                                    <div class="rounded-lg bg-white p-2"><span class="block text-[10px] text-brand-muted">Total</span>{{ $broadcast->recipient_count }}</div>
                                    <div class="rounded-lg bg-white p-2"><span class="block text-[10px] text-brand-muted">Terkirim</span>{{ $broadcast->sent_count }}</div>
                                    <div class="rounded-lg bg-white p-2"><span class="block text-[10px] text-brand-muted">Pending</span>{{ $broadcast->pending_count }}</div>
                                    <div class="rounded-lg bg-white p-2"><span class="block text-[10px] text-brand-muted">Gagal</span>{{ $broadcast->failed_count }}</div>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-xl bg-brand-surface p-3 text-xs font-semibold text-brand-muted">Belum ada siaran yang masuk antrean.</p>
                        @endforelse
                    </div>
                </div>
            </div>
