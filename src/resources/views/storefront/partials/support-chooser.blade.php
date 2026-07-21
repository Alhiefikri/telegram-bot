@php
    $supportId = $supportId ?? 'storefront-support';
    $supportClass = $supportClass ?? 'mt-5';
@endphp

@if (! empty($supportContext['categories']))
    <x-storefront.panel as="section" id="{{ $supportId }}" class="{{ $supportClass }}" aria-labelledby="{{ $supportId }}-title">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h2 id="{{ $supportId }}-title" class="font-display text-2xl font-black text-[var(--sf-ink)]">{{ $supportContext['title'] ?? 'Butuh bantuan?' }}</h2>
                @if (! empty($supportContext['body']))
                    <p class="mt-2 max-w-2xl text-sm font-bold leading-6 text-[var(--sf-muted)]">{{ $supportContext['body'] }}</p>
                @endif
            </div>
            <span class="inline-flex w-fit rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-1 text-xs font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)]">
                WhatsApp + Telegram Admin
            </span>
        </div>

        <div class="mt-5 grid gap-4">
            @foreach ($supportContext['categories'] as $category)
                <div class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 shadow-[4px_4px_0_var(--sf-shadow)]">
                    <p class="text-sm font-black leading-6 text-[var(--sf-ink)]">{{ $category['label'] }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($category['options'] as $option)
                            @if ($option['available'])
                                <a href="{{ $option['href'] }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="inline-flex min-h-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white px-4 py-3 text-center text-sm font-black text-[var(--sf-ink)] shadow-[3px_3px_0_var(--sf-shadow)] transition hover:-translate-y-0.5 hover:shadow-[5px_5px_0_var(--sf-shadow)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-blue)] focus:ring-offset-2"
                                   data-support-channel="{{ $option['key'] }}"
                                   data-support-category="{{ $category['key'] }}"
                                   data-support-message="{{ $option['message'] }}"
                                   @if (! empty($supportContext['subject']['order_id'])) data-support-order-id="{{ $supportContext['subject']['order_id'] }}" @endif
                                   @if (! empty($supportContext['subject']['order_id']) && ! empty($redirectUrl)) data-support-signed-url="{{ $redirectUrl }}" @endif
                                   @if (! empty($supportContext['subject']['product_id'])) data-support-product-id="{{ $supportContext['subject']['product_id'] }}" @endif>
                                    {{ $option['label'] }}
                                </a>
                            @else
                                <span class="inline-flex min-h-12 items-center justify-center rounded-lg border-[3px] border-[var(--sf-border)] bg-white/70 px-4 py-3 text-center text-sm font-black text-[var(--sf-muted)] opacity-80"
                                      aria-disabled="true"
                                      data-support-channel="{{ $option['key'] }}"
                                      data-support-category="{{ $category['key'] }}"
                                      data-support-message="{{ $option['message'] }}">
                                    {{ $option['label'] }} belum aktif
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-storefront.panel>
@endif
