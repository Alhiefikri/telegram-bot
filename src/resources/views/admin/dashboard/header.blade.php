            {{-- ═══════════════════════════════════════════════════════
                 HEADER CARD — Purple/Magenta Gradient
                 ═══════════════════════════════════════════════════════ --}}
            <x-admin-dashboard.card
                as="header"
                padding="compact"
                shadow="sm"
                class="header-gradient desktop-header relative overflow-hidden rounded-[1.5rem] text-neo-ink md:rounded-[2rem] md:p-6"
            >
                {{-- Diagonal stripe decoration --}}
                <div class="header-glow pointer-events-none absolute -right-6 top-0 h-36 w-36 opacity-20"></div>

                {{-- Top chip row --}}
                <div class="relative z-10 flex flex-wrap items-center justify-between gap-2">
                    <x-admin-dashboard.badge background="rgba(255,255,255,0.55)" color="#050505" dot>
                        Telegram Mini App
                    </x-admin-dashboard.badge>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-admin-dashboard.badge background="rgba(255,255,255,0.55)" color="#050505">
                            {{ $storeStatus['label'] }}
                        </x-admin-dashboard.badge>
                        <x-admin-dashboard.badge background="rgba(255,255,255,0.55)" color="#050505">
                            {{ $checkoutMaintenance['status_badge']['label'] }}
                        </x-admin-dashboard.badge>
                        <x-theme-toggle showLabel class="min-h-9 rounded-lg border-2 border-neo-ink bg-white/60 px-2.5 text-[10px] font-black text-neo-ink shadow-[2px_2px_0_#050505] transition-transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-neo-focus" />
                    </div>
                </div>

                {{-- Name --}}
                <h1 class="relative z-10 mt-4 max-w-[12ch] font-display text-[2rem] font-black leading-[0.95] tracking-tight text-neo-ink sm:text-[2.4rem]">
                    Halo, {{ $admin['name'] }}
                </h1>

                {{-- Role / tier info --}}
                <p class="relative z-10 mt-2 max-w-[22ch] text-xs font-bold leading-5 text-neo-ink/80">
                    {{ strtoupper($admin['role']) }} · {{ $storeStatus['hint'] }}
                </p>

                {{-- 2x2 stat cards grid --}}
                <section class="relative z-10 mt-4 grid grid-cols-2 gap-2 md:grid-cols-4 md:gap-3" aria-label="Ringkasan toko">
                    @foreach ($summary as $metric)
                        <x-admin-dashboard.stat-card
                            :label="$metric['label']"
                            :value="$metric['value']"
                            :hint="$metric['hint']"
                            :tone="$metric['tone']"
                            class="rounded-[1rem] shadow-[3px_3px_0_0_#050505]"
                        />
                    @endforeach
                </section>

                {{-- Action buttons --}}
                <div class="relative z-10 mt-4 grid grid-cols-2 gap-2 md:mt-5 md:gap-3">
                    <x-admin-dashboard.button
                        :href="$actions['shop']"
                        variant="neutral"
                        class="text-[11px] md:min-h-14 md:text-sm"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4.5 7.5h15l-.75 12.75H5.25L4.5 7.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="M8 7.5a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        Buka Toko
                    </x-admin-dashboard.button>

                    <x-admin-dashboard.button
                        type="button"
                        variant="warning"
                        class="text-[11px] md:min-h-14 md:text-sm"
                        x-on:click="shareStore()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                        <span x-text="copied ? 'Tersalin' : 'Bagikan Toko'">Bagikan Toko</span>
                    </x-admin-dashboard.button>
                </div>
            </x-admin-dashboard.card>
