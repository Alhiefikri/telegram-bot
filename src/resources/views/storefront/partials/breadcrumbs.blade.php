@if ($metadata->breadcrumbs !== [])
    <nav aria-label="{{ __('storefront_funnel.breadcrumbs') }}" class="storefront-layout-frame pt-4">
        <ol class="flex flex-wrap items-center gap-2 text-xs font-black text-[var(--sf-muted)]">
            @foreach ($metadata->breadcrumbs as $breadcrumb)
                <li class="flex items-center gap-2">
                    @if (! $loop->last)
                        <a href="{{ $breadcrumb['url'] }}" class="inline-flex min-h-11 items-center underline decoration-2 underline-offset-2 transition-colors hover:text-[var(--sf-accent)]">{{ $breadcrumb['name'] }}</a>
                        <span aria-hidden="true">/</span>
                    @else
                        <span aria-current="page" class="text-[var(--sf-ink)]">{{ $breadcrumb['name'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
