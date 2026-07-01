            {{-- ═══════════════════════════════════════════════════════
                 STATUS MESSAGE
                 ═══════════════════════════════════════════════════════ --}}
            @if (session('status'))
                <div class="mt-4 animate-slide-up rounded-xl p-4 text-sm font-bold" style="background: #e8ffd4; border: 2px solid #050505; box-shadow: 3px 3px 0px #050505; color: #050505;">
                    {{ session('status') }}
                </div>
            @endif
