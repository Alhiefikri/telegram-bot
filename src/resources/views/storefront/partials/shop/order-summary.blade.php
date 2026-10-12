<x-storefront.panel as="section" padding="sm" class="invoice-order-summary mt-4" aria-label="{{ __('storefront.invoice.shop.summary', [], $storefrontLocale ?? 'id') }}">
    <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
        <h2 class="font-display text-lg font-black text-[var(--sf-ink)]">{{ __('storefront.invoice.shop.summary', [], $storefrontLocale ?? 'id') }}</h2>
        <dl class="min-w-0">
            <dt class="text-sm font-semibold text-[var(--sf-muted)]">{{ __('storefront.invoice.qris.label.product_total', [], $storefrontLocale ?? 'id') }}</dt>
            <dd class="break-words font-display text-xl font-black text-[var(--sf-ink)]">{{ $storefront->idr($order->total_amount) }}</dd>
        </dl>
    </div>

    <h3 class="mt-3 text-sm font-bold text-[var(--sf-muted)]">{{ __('storefront.invoice.shop.products', [], $storefrontLocale ?? 'id') }}</h3>
    <ul class="mt-1 divide-y divide-[var(--sf-border)] text-sm font-semibold leading-6">
        @foreach ($order->orderItems as $item)
            <li class="flex min-w-0 items-start justify-between gap-3 py-2">
                <span class="min-w-0 break-words font-bold text-[var(--sf-ink)]">{{ $item->product_name }}</span>
                <span class="shrink-0 text-[var(--sf-muted)]">x{{ $item->quantity }}</span>
            </li>
        @endforeach
    </ul>

    <dl class="mt-2 grid gap-x-6 gap-y-2 border-t border-[var(--sf-border)] pt-3 text-sm sm:grid-cols-2">
        <div class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <dt class="font-semibold text-[var(--sf-muted)]">{{ __('storefront.invoice.shop.order_status', [], $storefrontLocale ?? 'id') }}</dt>
            <dd class="break-words font-bold text-[var(--sf-ink)]">{{ $orderStatusLabel ?? __('storefront.invoice.status.order.unknown', [], $storefrontLocale ?? 'id') }}</dd>
        </div>
        <div class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <dt class="font-semibold text-[var(--sf-muted)]">{{ __('storefront.invoice.shop.payment_status', [], $storefrontLocale ?? 'id') }}</dt>
            <dd class="break-words font-bold text-[var(--sf-ink)]">{{ $effectivePaymentStatus }}</dd>
        </div>
    </dl>
    @if (! $isPaid)
        <p class="mt-3 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
            @if ($isTerminal)
                {{ __('storefront.invoice.shop.stock_released', [], $storefrontLocale ?? 'id') }}
            @else
                {{ __('storefront.invoice.shop.stock_reserved', ['customer' => $order->customer?->first_name ?? __('storefront.invoice.shop.customer', [], $storefrontLocale ?? 'id')], $storefrontLocale ?? 'id') }}
            @endif
        </p>
    @endif
</x-storefront.panel>
