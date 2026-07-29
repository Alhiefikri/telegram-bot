            {{-- ═══════════════════════════════════════════════════════
             STATUS MESSAGE
             ═══════════════════════════════════════════════════════ --}}
            <div class="sr-only" aria-live="polite" aria-atomic="true"></div>
            @if (session('status'))
                <x-admin-dashboard.notice tone="success" class="mt-4 animate-slide-up rounded-xl" role="status" aria-live="polite">
                    {{ session('status') }}
                </x-admin-dashboard.notice>
            @endif
            @if (session('error'))
                <x-admin-dashboard.notice tone="danger" class="mt-4 animate-slide-up rounded-xl" role="alert" aria-live="assertive">
                    {{ session('error') }}
                </x-admin-dashboard.notice>
            @endif
