@props([
    'copy' => [],
    'labels' => true,
])

@foreach (['id', 'en'] as $locale)
    <div
        data-api-docs-block="{{ $locale }}"
        lang="{{ $locale }}"
        @class(['api-docs-prose', 'mt-5 border-t border-[var(--sf-border)] pt-5' => $locale === 'en'])
    >
        @if ($labels)
            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">
                {{ $locale === 'id' ? 'Bahasa Indonesia' : 'English' }}
            </p>
        @endif
        {!! \Illuminate\Support\Str::markdown($copy[$locale] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    </div>
@endforeach
