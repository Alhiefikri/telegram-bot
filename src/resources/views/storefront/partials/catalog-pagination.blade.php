@if ($catalogPage->hasPagination())
    @php($pagination = $catalogPage->pagination)
    <nav class="mt-10 flex flex-col gap-4 border-t-[3px] border-dashed border-[var(--sf-border)] pt-6 sm:flex-row sm:items-center sm:justify-between" aria-label="Navigasi halaman katalog">
        <p class="text-xs font-bold text-[var(--sf-muted)]">
            Menampilkan {{ $pagination['from'] }}–{{ $pagination['to'] }} dari {{ $pagination['total'] }} produk
        </p>

        <div class="flex flex-wrap items-center justify-center gap-2" aria-label="Halaman katalog">
            @if ($pagination['previousUrl'])
                <a href="{{ $pagination['previousUrl'] }}" rel="prev" class="neo-filter-inactive inline-flex min-h-10 items-center rounded-full border-[3px] px-3 py-2 text-xs font-black transition-all hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">Sebelumnya</a>
            @else
                <span aria-disabled="true" class="inline-flex min-h-10 items-center rounded-full border-[3px] border-[var(--sf-border)] px-3 py-2 text-xs font-black text-[var(--sf-muted)] opacity-60">Sebelumnya</span>
            @endif

            @foreach ($pagination['pages'] as $page)
                @if ($page['ellipsis'])
                    <span aria-hidden="true" class="px-1 text-sm font-black text-[var(--sf-muted)]">…</span>
                    <span class="sr-only">Halaman lain</span>
                @elseif ($page['current'])
                    <span aria-current="page" class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-full border-[3px] border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 py-2 text-xs font-black shadow-[3px_3px_0_var(--sf-shadow)]">{{ $page['label'] }}</span>
                @else
                    <a href="{{ $page['url'] }}" aria-label="Halaman {{ $page['label'] }}" class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-full border-[3px] border-[var(--sf-border)] px-3 py-2 text-xs font-black transition-all hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">{{ $page['label'] }}</a>
                @endif
            @endforeach

            @if ($pagination['nextUrl'])
                <a href="{{ $pagination['nextUrl'] }}" rel="next" class="neo-filter-inactive inline-flex min-h-10 items-center rounded-full border-[3px] px-3 py-2 text-xs font-black transition-all hover:-translate-y-0.5 hover:shadow-[4px_4px_0_var(--sf-shadow)] focus:outline-none focus:ring-2 focus:ring-[var(--sf-accent)]">Berikutnya</a>
            @else
                <span aria-disabled="true" class="inline-flex min-h-10 items-center rounded-full border-[3px] border-[var(--sf-border)] px-3 py-2 text-xs font-black text-[var(--sf-muted)] opacity-60">Berikutnya</span>
            @endif
        </div>
    </nav>
@endif
