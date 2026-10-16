@foreach ($guideSections as $section)
    <section id="{{ $section['id'] }}" class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[5px_5px_0_var(--sf-shadow)] sm:p-6">
        <h2 class="font-display text-2xl font-black leading-tight text-[var(--sf-ink)]">
            <span data-api-docs-block="id">{{ $section['title']['id'] }}</span>
            <span data-api-docs-block="en">{{ $section['title']['en'] }}</span>
        </h2>

        @include('storefront.partials.api-docs.bilingual', ['copy' => $section['body']])

        @if ($section['id'] === 'limits')
            @php
                $budgetHeaders = ['Anggaran', 'Batas'];
                $budgetRows = static fn (string $locale): array => array_map(
                    static fn (array $row): array => [$row['scope'], $row['bound']],
                    $tables->budgets($locale),
                );
            @endphp
            @foreach (['id', 'en'] as $locale)
                <div data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-5">
                    @include('storefront.partials.api-docs.table', [
                        'headers' => $budgetHeaders,
                        'rows' => $budgetRows($locale),
                        'caption' => $locale === 'id' ? 'Anggaran admission API per IP dan per akun' : 'API admission budgets per IP and per account',
                    ])
                </div>
            @endforeach
        @endif

        @if ($section['id'] === 'errors')
            @php
                $errorHeaders = ['Status', 'Kode', 'Arti'];
                $errorRows = static fn (string $locale): array => array_map(
                    static fn (array $row): array => [(string) $row['status'], $row['code'], $row['meaning']],
                    $tables->allErrors($locale),
                );
            @endphp
            @foreach (['id', 'en'] as $locale)
                <div data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-5">
                    @include('storefront.partials.api-docs.table', [
                        'headers' => $errorHeaders,
                        'rows' => $errorRows($locale),
                        'caption' => $locale === 'id' ? 'Kode error yang bisa dikembalikan API' : 'Machine codes this API can return',
                        'monoColumns' => [0, 1],
                    ])
                </div>
            @endforeach
        @endif
    </section>
@endforeach
