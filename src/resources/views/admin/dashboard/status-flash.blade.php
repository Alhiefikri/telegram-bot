            {{-- ═══════════════════════════════════════════════════════
                 STATUS MESSAGE
                 ═══════════════════════════════════════════════════════ --}}
            @if (session('status'))
                <x-admin-dashboard.notice tone="success" class="mt-4 animate-slide-up rounded-xl">
                    {{ session('status') }}
                </x-admin-dashboard.notice>
            @endif
            @if (session('error'))
                <x-admin-dashboard.notice tone="danger" class="mt-4 animate-slide-up rounded-xl">
                    {{ session('error') }}
                </x-admin-dashboard.notice>
            @endif
