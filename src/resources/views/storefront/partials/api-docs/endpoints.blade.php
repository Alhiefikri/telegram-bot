@php
    $requiredLabel = ['id' => ['yes' => 'Wajib', 'no' => 'Opsional'], 'en' => ['yes' => 'Required', 'no' => 'Optional']];
    $labels = [
        'parameters' => ['id' => 'Parameter', 'en' => 'Parameters'],
        'request' => ['id' => 'Body permintaan', 'en' => 'Request body'],
        'responses' => ['id' => 'Respons', 'en' => 'Responses'],
        'errors' => ['id' => 'Kode error', 'en' => 'Error codes'],
        'notes' => ['id' => 'Catatan', 'en' => 'Notes'],
        'examples' => ['id' => 'Contoh kode', 'en' => 'Code examples'],
    ];
    $parameterHeaders = ['Nama', 'Letak', 'Wajib', 'Tipe', 'Keterangan'];
    $requestHeaders = ['Field', 'Tipe', 'Wajib', 'Keterangan'];
    $responseHeaders = ['Status', 'Arti', 'Skema'];
    $errorHeaders = ['Status', 'Kode', 'Arti'];
    $sampleData = $samples->all();
@endphp

<section id="endpoint" class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[5px_5px_0_var(--sf-shadow)] sm:p-6">
    <h2 class="font-display text-2xl font-black leading-tight text-[var(--sf-ink)]">
        <span data-api-docs-block="id">Endpoint</span>
        <span data-api-docs-block="en">Endpoints</span>
    </h2>
    <p data-api-docs-block="id" lang="id" class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">
        Tujuh endpoint pada origin yang sama. Isi tabel field memakai nama field API dalam bahasa Inggris, sedangkan penjelasan perilakunya ada pada bagian di atas.
    </p>
    <p data-api-docs-block="en" lang="en" class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">
        Seven endpoints on the same origin. Field tables keep the API's own English field names; the behaviour explanations live in the sections above.
    </p>

    <div class="mt-6 grid gap-5">
        @foreach ($operations as $operation)
            @php
                $sample = $sampleData[$operation['responses'][0]['schema']] ?? null;
                $codes = $examples->for($operation['id']);
                $requestRows = $operation['request'] === null ? [] : $tables->fields($operation['request']['schema']);
            @endphp

            <article
                id="endpoint-{{ strtolower($operation['id']) }}"
                data-api-docs-search-item
                data-api-docs-search="{{ $tables->searchText($operation) }}"
                class="rounded-md border-[2px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4 sm:p-5"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span @class([
                        'inline-flex min-h-8 items-center rounded-md border-2 border-[var(--sf-border)] px-2 font-mono text-[11px] font-black tracking-[0.08em]',
                        'bg-[var(--sf-ink)] text-white' => $operation['method'] === 'POST',
                        'bg-[var(--sf-surface)] text-[var(--sf-ink)]' => $operation['method'] !== 'POST',
                    ])>{{ $operation['method'] }}</span>
                    <code class="font-mono text-sm font-black break-all text-[var(--sf-ink)]">{{ $operation['path'] }}</code>
                    <x-storefront.badge tone="warm" size="xs" shape="rounded">{{ $operation['tag'] }}</x-storefront.badge>
                </div>

                <h3 class="mt-3 font-display text-xl font-black leading-tight text-[var(--sf-ink)]">
                    <span data-api-docs-block="id">{{ $operation['title']['id'] }}</span>
                    <span data-api-docs-block="en">{{ $operation['title']['en'] }}</span>
                </h3>

                @foreach (['id', 'en'] as $locale)
                    <div data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-3 grid gap-2">
                        <p class="text-sm font-black leading-6 text-[var(--sf-ink)]">{{ $operation['summary'][$locale] }}</p>
                        <p class="text-sm font-semibold leading-7 text-[var(--sf-muted)]">{{ $operation['description'][$locale] }}</p>
                    </div>
                @endforeach

                @if ($operation['parameters'] !== [])
                    <h4 class="mt-5 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">
                        <span data-api-docs-block="id">{{ $labels['parameters']['id'] }}</span>
                        <span data-api-docs-block="en">{{ $labels['parameters']['en'] }}</span>
                    </h4>
                    @foreach (['id', 'en'] as $locale)
                        <div data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-2">
                            @include('storefront.partials.api-docs.table', [
                                'headers' => $parameterHeaders,
                                'rows' => array_map(static fn (array $row): array => [
                                    $row['name'],
                                    $row['location'],
                                    $requiredLabel[$locale][$row['required'] ? 'yes' : 'no'],
                                    $row['type'],
                                    $row['description'],
                                ], $tables->parameters($operation, $locale)),
                                'monoColumns' => [0, 1],
                                'caption' => $operation['method'].' '.$operation['path'].' parameters',
                            ])
                        </div>
                    @endforeach
                @endif

                @if ($operation['request'] !== null)
                    <h4 class="mt-5 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">
                        <span data-api-docs-block="id">{{ $labels['request']['id'] }}</span>
                        <span data-api-docs-block="en">{{ $labels['request']['en'] }}</span>
                    </h4>
                    @foreach (['id', 'en'] as $locale)
                        <p data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-2 text-sm font-semibold leading-7 text-[var(--sf-muted)]">
                            {{ $operation['request']['description'][$locale] }}
                        </p>
                    @endforeach
                    <div class="mt-2">
                        @include('storefront.partials.api-docs.table', [
                            'headers' => $requestHeaders,
                            'rows' => array_map(static fn (array $row): array => [
                                $row['field'],
                                $row['type'],
                                $row['required'] ? 'Wajib / Required' : 'Opsional / Optional',
                                $row['description'],
                            ], $requestRows),
                            'monoColumns' => [0],
                            'caption' => $operation['request']['schema'].' fields',
                        ])
                    </div>
                    @if (isset($sampleData[$operation['request']['schema']]))
                        <details class="mt-3 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] p-3">
                            <summary class="flex min-h-11 cursor-pointer items-center text-xs font-black text-[var(--sf-accent)]">Contoh body JSON / JSON body example</summary>
                            <div class="api-docs-code mt-2">
                                <pre tabindex="0" aria-label="Contoh body JSON {{ $operation['request']['schema'] }}"><code>{{ json_encode($sampleData[$operation['request']['schema']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                            </div>
                        </details>
                    @endif
                @endif

                <h4 class="mt-5 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">
                    <span data-api-docs-block="id">{{ $labels['responses']['id'] }}</span>
                    <span data-api-docs-block="en">{{ $labels['responses']['en'] }}</span>
                </h4>
                @foreach (['id', 'en'] as $locale)
                    <div data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-2">
                        @include('storefront.partials.api-docs.table', [
                            'headers' => $responseHeaders,
                            'rows' => array_map(static fn (array $response): array => [
                                (string) $response['status'],
                                $response['description'][$locale],
                                $response['schema'],
                            ], $operation['responses']),
                            'monoColumns' => [0, 2],
                            'caption' => $operation['method'].' '.$operation['path'].' responses',
                        ])
                    </div>
                @endforeach

                @php $operationErrors = $tables->errors($operation, 'id'); @endphp
                @if ($operationErrors !== [])
                    <h4 class="mt-5 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">
                        <span data-api-docs-block="id">{{ $labels['errors']['id'] }}</span>
                        <span data-api-docs-block="en">{{ $labels['errors']['en'] }}</span>
                    </h4>
                    @foreach (['id', 'en'] as $locale)
                        <div data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-2">
                            @include('storefront.partials.api-docs.table', [
                                'headers' => $errorHeaders,
                                'rows' => array_map(static fn (array $row): array => [
                                    (string) $row['status'],
                                    $row['code'],
                                    $row['meaning'],
                                ], $tables->errors($operation, $locale)),
                                'monoColumns' => [0, 1],
                                'caption' => $operation['method'].' '.$operation['path'].' error codes',
                            ])
                        </div>
                    @endforeach
                @endif

                @if ($operation['notes'] !== [])
                    <h4 class="mt-5 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">
                        <span data-api-docs-block="id">{{ $labels['notes']['id'] }}</span>
                        <span data-api-docs-block="en">{{ $labels['notes']['en'] }}</span>
                    </h4>
                    @foreach (['id', 'en'] as $locale)
                        <ul data-api-docs-block="{{ $locale }}" lang="{{ $locale }}" class="mt-2 grid gap-2 text-sm font-semibold leading-6 text-[var(--sf-muted)]">
                            @foreach ($operation['notes'] as $note)
                                <li class="flex gap-2">
                                    <span class="mt-2 h-2 w-2 shrink-0 rounded-full border-2 border-[var(--sf-border)] bg-[var(--sf-accent)]"></span>
                                    <span>{{ $note[$locale] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                @endif

                @if ($sample !== null)
                    <details class="mt-4 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] p-3">
                        <summary class="flex min-h-11 cursor-pointer items-center text-xs font-black text-[var(--sf-accent)]">
                            <span data-api-docs-block="id">Contoh respons JSON</span>
                            <span data-api-docs-block="en">Response JSON example</span>
                        </summary>
                        <div class="api-docs-code mt-2">
                            <pre tabindex="0" aria-label="Contoh respons JSON {{ $operation['path'] }}"><code>{{ json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        </div>
                    </details>
                @endif

                <h4 class="mt-5 text-[11px] font-black uppercase tracking-[0.14em] text-[var(--sf-muted)]">
                    <span data-api-docs-block="id">{{ $labels['examples']['id'] }}</span>
                    <span data-api-docs-block="en">{{ $labels['examples']['en'] }}</span>
                </h4>
                <div class="mt-2 grid gap-3">
                    @foreach ($languages as $key => $language)
                        @php $code = $codes[$key]; @endphp
                        <div data-api-docs-example-block="{{ $key }}" data-copy-group class="api-docs-code overflow-hidden">
                            <div class="flex items-center justify-between gap-3 border-b border-[var(--sf-trend-border)] px-3 py-2">
                                <span class="font-mono text-[11px] font-black text-[var(--sf-trend-muted)]">{{ $language['label'] }}</span>
                                <button
                                    type="button"
                                    hidden
                                    data-api-docs-copy
                                    data-copy-text="{{ $code }}"
                                    data-copy-success="Contoh {{ $language['label'] }} tersalin."
                                    class="inline-flex min-h-11 items-center rounded-lg border border-[var(--sf-trend-border)] bg-[var(--sf-trend-card)] px-3 text-xs font-black text-[var(--sf-trend-text)] transition-colors hover:bg-[var(--sf-trend-border)]"
                                >Copy</button>
                            </div>
                            <pre tabindex="0" aria-label="{{ $language['label'] }}: {{ $operation['method'] }} {{ $operation['path'] }}"><code>{{ $code }}</code></pre>
                            <span class="sr-only" aria-live="polite" data-copy-status></span>
                        </div>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
