<footer class="footer-dark border-t-[3px] border-[var(--sf-border)] bg-[var(--sf-ink)] pb-10 pt-16 text-white">
    <x-storefront.frame data-storefront-footer-frame class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
        <div class="space-y-4">
            @include('storefront.components.brand-mark', ['brand' => $brand])
            <p class="text-sm font-semibold leading-relaxed text-white/70">
                {{ __('storefront_funnel.footer_summary') }}
            </p>
            <div class="flex items-center gap-2" aria-hidden="true">
                <span class="h-3 w-3 rotate-12 rounded-sm border-2 border-white/40 bg-[var(--sf-yellow)]"></span>
                <span class="h-3 w-3 rounded-full border-2 border-white/40 bg-[var(--sf-violet)]"></span>
                <span class="h-3 w-3 -rotate-12 rounded-sm border-2 border-white/40 bg-[var(--sf-lime)]"></span>
            </div>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black uppercase tracking-[0.14em] text-[var(--sf-yellow)]">{{ __('storefront_funnel.products') }}</h3>
            <ul class="space-y-3 text-sm font-semibold text-white/70">
                <li><a href="{{ $shopUrl ?? route('storefront.shop.index') }}" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.full_catalog') }}</a></li>
                <li><a href="{{ route('storefront.guides.index') }}" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.help_center') }}</a></li>
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#faq" class="transition-colors hover:text-[var(--sf-yellow)]">FAQ</a></li>
            </ul>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black uppercase tracking-[0.14em] text-[var(--sf-yellow)]">{{ __('storefront_funnel.store') }}</h3>
            <ul class="space-y-3 text-sm font-semibold text-white/70">
                <li><a href="{{ $homeUrl ?? route('storefront.home') }}#support" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.about') }}</a></li>
                <li><a href="{{ route('storefront.policies.privacy') }}" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.privacy_policy') }}</a></li>
                <li><a href="{{ route('storefront.policies.terms') }}" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.terms_conditions') }}</a></li>
                <li><a href="{{ route('storefront.policies.refund') }}" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.refund_policy') }}</a></li>
            </ul>
        </div>

        <div>
            <h3 class="mb-4 font-display text-sm font-black uppercase tracking-[0.14em] text-[var(--sf-yellow)]">{{ __('storefront_funnel.support') }}</h3>
            <ul class="space-y-3 text-sm font-semibold text-white/70">
                <li><a href="{{ route('storefront.order-access.create') }}" class="font-black text-[var(--sf-yellow)] transition-colors hover:text-white">{{ __('storefront_funnel.order_access_title') }}</a></li>
                <li><a href="{{ route('customer.account') }}" class="font-black text-[var(--sf-yellow)] transition-colors hover:text-white">{{ __('storefront_funnel.sign_up_in') }}</a></li>
                <li><a href="{{ route('storefront.policies.recovery') }}" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.order_recovery') }}</a></li>
                <li><a href="{{ route('storefront.guides.index') }}" class="transition-colors hover:text-[var(--sf-yellow)]">{{ __('storefront_funnel.help_center') }}</a></li>
                <li><span>{{ __('storefront_funnel.save_access') }}</span></li>
            </ul>
        </div>
    </x-storefront.frame>

    <x-storefront.frame class="mt-16 flex flex-col items-center justify-between border-t-[3px] border-white/15 pt-8 text-xs font-bold text-white/60 md:flex-row">
        <p>&copy; {{ now()->year }} {{ __('storefront_funnel.copyright') }}</p>
        <div class="mt-4 flex items-center gap-4 md:mt-0">
            <span>{{ __('storefront_funnel.web_qris') }}</span>
            <span>{{ __('storefront_funnel.self_service_access') }}</span>
        </div>
    </x-storefront.frame>
</footer>
