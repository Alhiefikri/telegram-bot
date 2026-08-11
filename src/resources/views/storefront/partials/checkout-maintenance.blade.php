@php
    $maintenance = $maintenance
        ?? ($checkoutMaintenance ?? (isset($catalogPage) ? $catalogPage->checkoutMaintenance : null));
    $notice = $checkoutMaintenanceNotice ?? ($maintenance ? \App\Storefront\StorefrontCheckoutMaintenanceCopy::notice($maintenance) : '');
@endphp

@if ($maintenance?->active)
    <section
        role="alert"
        aria-labelledby="checkout-maintenance-title"
        class="mx-auto mt-6 max-w-6xl rounded-xl border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] p-4 text-[var(--sf-ink)] shadow-[5px_5px_0_var(--sf-shadow)] sm:p-5"
    >
        <div class="flex items-start gap-3">
            <span class="mt-0.5 text-xl" aria-hidden="true">⏳</span>
            <div>
                <h2 id="checkout-maintenance-title" class="font-display text-sm font-black uppercase tracking-wide">Checkout sedang maintenance</h2>
                <p class="mt-2 text-sm font-semibold leading-6">{{ $notice }}</p>
            </div>
        </div>
    </section>
@endif
