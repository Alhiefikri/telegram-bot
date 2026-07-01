            {{-- ═══════════════════════════════════════════════════════
                 HEADER CARD — Purple/Magenta Gradient
                 ═══════════════════════════════════════════════════════ --}}
            <header class="header-gradient desktop-header relative overflow-hidden rounded-[1.5rem] p-4 text-neo-ink md:rounded-[2rem] md:p-6" style="border: 3px solid #050505; box-shadow: 5px 5px 0px #050505;">
                {{-- Diagonal stripe decoration --}}
                <div class="pointer-events-none absolute -right-6 top-0 h-36 w-36 opacity-20" style="background: radial-gradient(circle at center, rgba(255,255,255,0.85) 0, rgba(255,255,255,0.15) 42%, transparent 70%);"></div>

                {{-- Top chip row --}}
                <div class="relative z-10 flex flex-wrap items-center justify-between gap-2">
                    <span class="neo-badge" style="background: rgba(255,255,255,0.55); color: #050505;">
                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        Telegram Mini App
                    </span>
                    <span class="neo-badge" style="background: rgba(255,255,255,0.55); color: #050505;">
                        {{ $storeStatus['label'] }}
                    </span>
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
                        <div class="{{ $metric['card_class'] }} rounded-[1rem] p-3 md:p-4" style="border: 3px solid #050505; box-shadow: 3px 3px 0px #050505;">
                            <p class="font-display text-[10px] font-black uppercase tracking-[0.18em]" style="color: #050505;">{{ $metric['label'] }}</p>
                            <p class="mt-1 break-words font-display text-lg font-black leading-none" style="color: #050505;">{{ $metric['value'] }}</p>
                            @if ($metric['hint'])
                                <p class="mt-1 text-[10px] font-semibold opacity-70" style="color: #050505;">{{ $metric['hint'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </section>

                {{-- Action buttons --}}
                <div class="relative z-10 mt-4 grid grid-cols-2 gap-2 md:mt-5 md:gap-3">
                    <a
                        href="{{ $actions['shop'] }}"
                        class="neo-btn neo-btn-white text-[11px] md:min-h-14 md:text-sm"
                        style="box-shadow: 3px 3px 0px rgba(0,0,0,0.35);"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4.5 7.5h15l-.75 12.75H5.25L4.5 7.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="M8 7.5a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        Buka Toko
                    </a>

                    <button
                        type="button"
                        class="neo-btn neo-btn-yellow text-[11px] md:min-h-14 md:text-sm"
                        style="box-shadow: 3px 3px 0px rgba(0,0,0,0.35);"
                        x-on:click="shareStore()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M8.5 12.5 15.5 16M15.5 8 8.5 11.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <circle cx="6" cy="12.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="18" cy="7" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="18" cy="17" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                        <span x-text="copied ? 'Tersalin' : 'Bagikan Toko'">Bagikan Toko</span>
                    </button>
                </div>
            </header>
