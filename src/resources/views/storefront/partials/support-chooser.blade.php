@php
    $supportId = $supportId ?? 'storefront-support-'.\Illuminate\Support\Str::random(8);
    $supportClass = $supportClass ?? 'mt-5';
    $supportSelectId = $supportId.'-issue-'.\Illuminate\Support\Str::random(8);
@endphp

@if (! empty($supportContext['categories']))
    <x-storefront.panel as="section" id="{{ $supportId }}" padding="sm" class="invoice-support-chooser {{ $supportClass }}" aria-labelledby="{{ $supportId }}-title" data-support-chooser>
        <h2 id="{{ $supportId }}-title" class="font-display text-lg font-black text-[var(--sf-ink)]">{{ $supportContext['title'] ?? 'Butuh bantuan?' }}</h2>
        @if (! empty($supportContext['body']))
            <p class="mt-1 text-sm font-semibold leading-6 text-[var(--sf-muted)]">{{ $supportContext['body'] }}</p>
        @endif

        <div class="mt-3" data-support-select-field hidden>
            <label for="{{ $supportSelectId }}" class="block text-sm font-bold text-[var(--sf-ink)]">Pilih kendala</label>
            <select id="{{ $supportSelectId }}" class="invoice-support-select mt-2 min-h-11 w-full min-w-0 rounded-lg border-2 border-[var(--sf-muted)] bg-[var(--sf-surface)] px-3 py-2 text-base font-semibold text-[var(--sf-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]" data-support-select>
                @foreach ($supportContext['categories'] as $category)
                    <option value="{{ $category['key'] }}" @disabled(! collect($category['options'])->contains(fn ($option) => $option['available']))>{{ $category['label'] }}</option>
                @endforeach
            </select>
        </div>

        <details class="mt-3" data-support-options>
            <summary class="min-h-11 cursor-pointer py-2 text-sm font-bold leading-6 text-[var(--sf-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]" data-support-fallback>Pilih kendala dan kontak admin</summary>
            <div class="space-y-4" data-support-groups>
                @foreach ($supportContext['categories'] as $category)
                    <section data-support-group="{{ $category['key'] }}">
                        <h3 class="mb-2 text-sm font-bold leading-6 text-[var(--sf-ink)]" data-support-group-title>{{ $category['label'] }}</h3>
                        <div class="flex flex-wrap gap-2">
                            @forelse (collect($category['options'])->where('available', true) as $option)
                                <a href="{{ $option['href'] }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="invoice-support-action inline-flex min-h-11 items-center justify-center rounded-lg border-2 border-[var(--sf-muted)] bg-[var(--sf-surface)] px-4 py-2 text-center text-sm font-bold text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface-warm)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sf-accent)]"
                                   data-support-channel="{{ $option['key'] }}"
                                   data-support-category="{{ $category['key'] }}"
                                   data-support-message="{{ $option['message'] }}"
                                   @if (! empty($supportContext['subject']['order_id'])) data-support-order-id="{{ $supportContext['subject']['order_id'] }}" @endif
                                   @if (! empty($supportContext['subject']['order_id']) && ! empty($redirectUrl)) data-support-signed-url="{{ $redirectUrl }}" @endif
                                   @if (! empty($supportContext['subject']['product_id'])) data-support-product-id="{{ $supportContext['subject']['product_id'] }}" @endif>
                                    {{ $option['label'] }}
                                </a>
                            @empty
                                <p class="text-sm font-semibold leading-6 text-[var(--sf-muted)]">Kontak untuk kendala ini belum tersedia.</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </details>
    </x-storefront.panel>
@endif
