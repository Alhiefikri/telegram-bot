<div id="mobile-menu-backdrop" class="fixed inset-0 z-40 hidden bg-[var(--sf-ink)]/45 opacity-0 transition-opacity duration-200 motion-reduce:transition-none lg:hidden" aria-hidden="true" :class="{ hidden: !isVisible, 'opacity-0': !isAnimating, 'opacity-100': isAnimating }" @click.self="close()"></div>

<div id="mobile-menu" class="fixed inset-y-0 left-0 z-50 hidden w-[min(84vw,22rem)] -translate-x-full overflow-y-auto border-r border-[var(--sf-border)] bg-[var(--sf-surface)] text-[var(--sf-ink)] shadow-lg transition-transform duration-300 ease-out motion-reduce:transition-none lg:hidden" role="dialog" aria-modal="true" inert aria-hidden="true" aria-label="{{ __('storefront_funnel.store_navigation') }}" :class="{ hidden: !isVisible, '-translate-x-full': !isAnimating, 'translate-x-0': isAnimating }" :aria-hidden="isOpen ? 'false' : 'true'" :inert="!isOpen" @click="closeOnLink($event)">
    <div class="flex items-center justify-between gap-3 border-b border-[var(--sf-border)] px-4 py-3">
        <a href="{{ $homeUrl }}" aria-label="{{ __('storefront_funnel.brand_home', ['brand' => $brand]) }}">
            @include('storefront.components.brand-mark', ['brand' => $brand])
        </a>
        <button id="mobile-menu-close" type="button" class="grid size-11 place-items-center rounded-lg border border-[var(--sf-border)] text-xl" aria-label="{{ __('storefront_funnel.close_menu') }}" @click="close()">×</button>
    </div>
    <nav class="grid gap-1 p-4" aria-label="{{ __('storefront_funnel.mobile_menu') }}">
        @include('storefront.partials.navigation.links')
        @include('storefront.partials.navigation.account')
    </nav>
</div>
