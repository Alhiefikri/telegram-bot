@php
    $origin = rtrim(url('/'), '/');
    $baseUrl = $origin.\App\ApiDocs\CustomerApiDocumentation::API_PREFIX;
    $guideSections = $guide->sections();
    $operations = $documentation->operations();
    $languages = $examples->languages();
    $machineFiles = [
        ['file' => 'openapi.json', 'url' => route('storefront.api-docs.openapi'), 'label' => 'OpenAPI 3.1', 'note' => 'Spesifikasi mesin untuk tool, linter, dan generator klien.'],
        ['file' => 'reference.md', 'url' => route('storefront.api-docs.reference'), 'label' => 'Markdown', 'note' => 'Referensi teks lengkap, terbaca tanpa JavaScript.'],
        ['file' => 'llms.txt', 'url' => route('storefront.llms'), 'label' => 'Indeks AI', 'note' => 'Indeks ringkas di root website yang menautkan keduanya.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white">
    <div class="storefront-shell relative z-10 flex min-h-screen flex-col justify-between overflow-x-clip">
        @include('storefront.partials.header')

        <main class="flex-grow px-5 py-12 sm:px-6 sm:py-16" data-api-docs>
            <div class="mx-auto max-w-6xl">
                <header class="max-w-3xl">
                    <h1 class="text-balance font-display text-4xl font-black leading-tight text-[var(--sf-ink)] sm:text-5xl">{{ $documentation->title()['id'] }}</h1>
                    <p class="mt-4 text-lg font-black text-[var(--sf-ink)]">{{ $documentation->title()['en'] }}</p>
                    <p lang="id" class="mt-4 text-base font-semibold leading-7 text-[var(--sf-ink)]">{{ $documentation->lead()['id'] }}</p>
                    <p lang="en" class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-ink)]">{{ $documentation->lead()['en'] }}</p>

                    <div class="mt-6 flex flex-wrap items-center gap-3 text-sm font-bold" data-copy-group>
                        <span class="text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">Base URL</span>
                        <code class="neo-panel-tight inline-flex min-h-11 items-center px-3 font-mono text-xs font-black text-[var(--sf-ink)]">{{ $baseUrl }}</code>
                        <button
                            type="button"
                            hidden
                            data-api-docs-copy
                            data-copy-text="{{ $baseUrl }}"
                            data-copy-success="Base URL tersalin."
                            class="neo-button inline-flex min-h-11 items-center justify-center bg-[var(--sf-surface)] px-3 text-xs font-black text-[var(--sf-ink)]"
                        >Copy</button>
                        <span class="sr-only" aria-live="polite" data-copy-status></span>
                    </div>
                </header>

                <ul class="mt-6 grid gap-3 sm:grid-cols-3">
                    @foreach ($machineFiles as $file)
                        <li>
                            <a href="{{ $file['url'] }}" class="neo-panel-tight block h-full bg-[var(--sf-surface)] p-4 transition-transform duration-200 hover:-translate-y-0.5">
                                <span class="font-mono text-xs font-black text-[var(--sf-accent)]">{{ $file['file'] }}</span>
                                <span class="mt-1 block text-sm font-black text-[var(--sf-ink)]">{{ $file['label'] }}</span>
                                <span class="mt-1 block text-xs font-semibold leading-5 text-[var(--sf-muted)]">{{ $file['note'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-8 grid gap-4 rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-4 shadow-[5px_5px_0_var(--sf-shadow)] sm:p-5">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span id="api-docs-lang-label" class="text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">Bahasa penjelasan</span>
                        <div role="group" aria-labelledby="api-docs-lang-label" hidden data-api-docs-lang-control class="flex flex-wrap gap-2">
                            @foreach (['all' => 'Keduanya', 'id' => 'Indonesia', 'en' => 'English'] as $value => $label)
                                <button
                                    type="button"
                                    data-api-docs-lang-button="{{ $value }}"
                                    aria-pressed="true"
                                    class="min-h-11 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] px-3 text-xs font-black text-[var(--sf-ink)] aria-pressed:border-[var(--sf-accent)] aria-pressed:bg-[var(--sf-accent-soft)] aria-pressed:text-[var(--sf-ink)]"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                        <span class="text-xs font-semibold text-[var(--sf-muted)]">Penjelasan lengkap tersedia dalam dua bahasa; tanpa JavaScript keduanya ditampilkan.</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span id="api-docs-example-label" class="text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">Bahasa contoh kode</span>
                        <div role="group" aria-labelledby="api-docs-example-label" hidden data-api-docs-example-control class="flex flex-wrap gap-2">
                            @foreach ($languages as $key => $language)
                                <button
                                    type="button"
                                    data-api-docs-example-button="{{ $key }}"
                                    aria-pressed="true"
                                    class="min-h-11 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] px-3 text-xs font-black text-[var(--sf-ink)] aria-pressed:border-[var(--sf-accent)] aria-pressed:bg-[var(--sf-accent-soft)] aria-pressed:text-[var(--sf-ink)]"
                                >{{ $language['label'] }}</button>
                            @endforeach
                            <button
                                type="button"
                                data-api-docs-example-button="all"
                                aria-pressed="true"
                                class="min-h-11 rounded-lg border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] px-3 text-xs font-black text-[var(--sf-ink)] aria-pressed:border-[var(--sf-accent)] aria-pressed:bg-[var(--sf-accent-soft)] aria-pressed:text-[var(--sf-ink)]"
                            >Semua</button>
                        </div>
                        <span class="text-xs font-semibold text-[var(--sf-muted)]">Empat bahasa contoh untuk setiap endpoint; pilihan ini berlaku untuk seluruh halaman.</span>
                    </div>

                    <div class="grid gap-2">
                        <label for="api-docs-filter" class="sr-only">Cari endpoint atau skema</label>
                        <input
                            id="api-docs-filter"
                            type="search"
                            hidden
                            data-api-docs-filter
                            placeholder="Cari endpoint atau skema, misalnya orders atau delivery"
                            class="neo-input h-11 w-full px-3 text-sm font-bold text-[var(--sf-ink)] placeholder-[var(--sf-muted)]"
                        >
                        <p hidden data-api-docs-empty class="text-xs font-black text-[var(--sf-warning)]">Tidak ada endpoint atau skema yang cocok dengan pencarian itu.</p>
                    </div>
                </div>

                <div class="mt-10 lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start lg:gap-10">
                    <details class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-4 shadow-[5px_5px_0_var(--sf-shadow)] lg:hidden">
                        <summary class="flex min-h-11 cursor-pointer items-center text-sm font-black text-[var(--sf-ink)]">Daftar isi</summary>
                        <div class="mt-3">
                            @include('storefront.partials.api-docs.toc', ['guideSections' => $guideSections, 'operations' => $operations, 'tables' => $tables])
                        </div>
                    </details>

                    <nav class="api-docs-toc hidden lg:block" aria-label="Daftar isi">
                        @include('storefront.partials.api-docs.toc', ['guideSections' => $guideSections, 'operations' => $operations, 'tables' => $tables])
                    </nav>

                    <div class="mt-8 grid gap-6 lg:mt-0">
                        @include('storefront.partials.api-docs.guide', ['guideSections' => $guideSections, 'tables' => $tables])
                        @include('storefront.partials.api-docs.endpoints', [
                            'operations' => $operations,
                            'languages' => $languages,
                            'examples' => $examples,
                            'tables' => $tables,
                            'samples' => $samples,
                        ])
                        @include('storefront.partials.api-docs.schemas', ['schemas' => $documentation->schemas(), 'tables' => $tables, 'samples' => $samples])
                    </div>
                </div>
            </div>
        </main>

        @include('storefront.partials.footer')
    </div>
</body>
</html>
