            <div x-show="activeTab === 'telegram-channel'" class="mt-4 space-y-4">
                <section class="payment-section" aria-label="Telegram Channel">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-[10px] font-bold uppercase tracking-wider" style="color: #64748b;">Telegram Channel</p>
                            <h2 class="mt-1 font-display text-base font-bold" style="color: #050505;">Setup Store Channel</h2>
                        </div>
                        <span class="neo-badge" style="background: {{ $storeChannel['status']['tone'] === 'emerald' ? '#e8ffd4' : ($storeChannel['status']['tone'] === 'rose' ? '#fee2e2' : '#fef3c7') }}; color: #050505;">{{ $storeChannel['status']['label'] }}</span>
                    </div>
                    <p class="mt-2 text-xs font-semibold" style="color: #64748b;">{{ $storeChannel['status']['hint'] }}</p>

                    @error('channel_username', 'storeChannel')<p class="mt-3 rounded-lg p-2 text-xs font-bold text-white" style="background: #ef4444; border: 2px solid #050505;">{{ $message }}</p>@enderror
                    @error('channel_link', 'storeChannel')<p class="mt-3 rounded-lg p-2 text-xs font-bold text-white" style="background: #ef4444; border: 2px solid #050505;">{{ $message }}</p>@enderror

                    <form method="POST" action="{{ $storeChannel['update_url'] }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block text-xs font-bold">Username channel (@channel)</label>
                        <input name="channel_username" value="{{ old('channel_username', $storeChannel['setting']->channel_username) }}" class="w-full rounded-xl bg-white px-3 py-2 text-sm font-semibold" style="border: 2px solid #050505;" placeholder="@lhie_store">
                        <label class="block text-xs font-bold">Link channel publik</label>
                        <input name="channel_link" value="{{ old('channel_link', $storeChannel['setting']->channel_link) }}" class="w-full rounded-xl bg-white px-3 py-2 text-sm font-semibold" style="border: 2px solid #050505;" placeholder="https://t.me/lhie_store">
                        <div class="rounded-xl bg-white p-3 text-xs font-semibold" style="border: 2px solid #050505;">
                            <p>Resolved chat ID: <strong>{{ $storeChannel['setting']->telegram_chat_id ?: 'Belum ada' }}</strong></p>
                            <p>Last test: <strong>{{ $storeChannel['setting']->last_tested_at?->format('Y-m-d H:i') ?: 'Belum pernah' }}</strong></p>
                            <p>Message ID: <strong>{{ $storeChannel['setting']->last_test_message_id ?: '-' }}</strong></p>
                            @if($storeChannel['setting']->last_test_error)<p class="text-red-600">Reason: {{ $storeChannel['setting']->last_test_error }}</p>@endif
                        </div>
                        <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="community_gate_enabled" value="1" @checked(old('community_gate_enabled', $storeChannel['setting']->community_gate_enabled))> Community Gate</label>
                        <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="sales_proof_enabled" value="1" @checked(old('sales_proof_enabled', $storeChannel['setting']->sales_proof_enabled))> Sales Proof Post</label>
                        @if(($storeChannel['setting']->community_gate_enabled || $storeChannel['setting']->sales_proof_enabled) && ! $storeChannel['ready'])
                            <p class="rounded-lg p-2 text-xs font-bold" style="background: #fef3c7; border: 2px solid #050505;">Warning: toggle aktif tetapi setup channel belum ready.</p>
                        @endif
                        @if($storeChannel['can_manage'])
                            <button class="product-action-btn neo-btn-yellow w-full" type="submit">Simpan Pengaturan</button>
                        @else
                            <p class="rounded-lg p-2 text-xs font-semibold" style="background: #fff; border: 2px solid #050505; color: #64748b;">Hanya owner/admin yang bisa mengubah Telegram Channel.</p>
                        @endif
                    </form>
                    @if($storeChannel['can_manage'])
                        <form method="POST" action="{{ $storeChannel['test_url'] }}" class="mt-3">@csrf<button class="product-action-btn neo-btn-purple w-full" type="submit">Test Channel</button></form>
                    @endif
                </section>
            </div>
