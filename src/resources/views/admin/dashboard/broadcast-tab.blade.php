            {{-- ╔═════════════════════════════════════════════════════╗
                 ║  BROADCAST TAB (hidden from bottom nav)             ║
                 ╚═════════════════════════════════════════════════════╝ --}}
            <div x-cloak x-show="activeTab === 'broadcast'" class="mt-4 space-y-4">

                <div>
                    <h2 class="font-display text-lg font-black uppercase tracking-[0.18em]" style="color: #050505;">📢 Siaran</h2>
                    <p class="mt-1 text-xs font-semibold" style="color: #64748b;">Kirim info ke pengguna aktif lewat bot</p>
                </div>

                @if ($errors->broadcast->any())
                    <div class="animate-slide-up rounded-xl p-4 text-sm font-bold text-white" style="background: #ef4444; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505;">
                        Periksa kembali pesan broadcast.
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.broadcast.store') }}" class="space-y-4">
                    @csrf

                    <div class="rounded-xl p-4" style="background: #fef9c3; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505;">
                        <p class="text-xs font-bold" style="color: #050505;">
                            ⚠️ Jangan isi data akun, password, kode OTP, atau secret pembeli di broadcast. Data akun hanya dikirim otomatis ke pembeli setelah pembayaran lunas.
                        </p>
                    </div>

                    <div>
                        <label for="broadcast-message" class="font-display text-xs font-bold uppercase tracking-wider" style="color: #050505;">Pesan</label>
                        <textarea
                            id="broadcast-message"
                            name="message"
                            rows="7"
                            placeholder="Contoh: Stok Canva Pro sudah ready. Silakan cek /katalog."
                            class="neo-textarea mt-2"
                        >{{ old('message') }}</textarea>
                        @error('message', 'broadcast')
                            <p class="mt-2 text-sm font-bold" style="color: #ef4444;">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="neo-btn neo-btn-purple w-full text-sm">
                        📤 Kirim Siaran
                    </button>
                </form>
            </div>
