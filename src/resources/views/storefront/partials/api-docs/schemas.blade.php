@php
    $sampleData = $samples->all();
    $schemaLabels = ['Field', 'Tipe', 'Wajib', 'Keterangan'];
@endphp

<section id="skema" class="rounded-lg border-[3px] border-[var(--sf-border)] bg-[var(--sf-surface)] p-5 shadow-[5px_5px_0_var(--sf-shadow)] sm:p-6">
    <h2 class="font-display text-2xl font-black leading-tight text-[var(--sf-ink)]">
        <span data-api-docs-block="id">Skema data</span>
        <span data-api-docs-block="en">Data schemas</span>
    </h2>
    <p data-api-docs-block="id" lang="id" class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">
        Setiap respons memakai allowlist field yang tetap. Tabel di bawah memakai nama field API apa adanya, termasuk nilai enum dan format uangnya.
    </p>
    <p data-api-docs-block="en" lang="en" class="mt-3 text-sm font-semibold leading-7 text-[var(--sf-muted)]">
        Every response uses a fixed field allowlist. The tables below keep the API's own field names, including enum values and money formats.
    </p>

    <div class="mt-6 grid gap-4">
        @foreach ($schemas as $name => $schema)
            @php
                $rows = $tables->fields($name);
                $search = strtolower($name.' '.($schema['description'] ?? '').' '.implode(' ', array_keys($schema['properties'] ?? [])));
            @endphp
            @if ($rows === [])
                @continue
            @endif

            <article
                id="skema-{{ strtolower($name) }}"
                data-api-docs-search-item
                data-api-docs-search="{{ $search }}"
                class="rounded-md border-[2px] border-[var(--sf-border)] bg-[var(--sf-surface-warm)] p-4"
            >
                <h3 class="font-mono text-sm font-black text-[var(--sf-ink)]">{{ $name }}</h3>
                @if (! empty($schema['description']))
                    <p class="mt-2 text-xs font-semibold leading-6 text-[var(--sf-muted)]">{{ $schema['description'] }}</p>
                @endif

                <div class="mt-3">
                    @include('storefront.partials.api-docs.table', [
                        'headers' => $schemaLabels,
                        'rows' => array_map(static fn (array $row): array => [
                            $row['field'],
                            $row['type'],
                            $row['required'] ? 'Wajib / Required' : 'Opsional / Optional',
                            $row['description'],
                        ], $rows),
                        'monoColumns' => [0],
                        'caption' => $name.' fields',
                    ])
                </div>

                @if (isset($sampleData[$name]))
                    <details class="mt-3 rounded-md border-2 border-[var(--sf-border)] bg-[var(--sf-surface)] p-3">
                        <summary class="flex min-h-11 cursor-pointer items-center text-xs font-black text-[var(--sf-accent)]">
                            <span data-api-docs-block="id">Contoh JSON</span>
                            <span data-api-docs-block="en">JSON example</span>
                        </summary>
                        <div class="api-docs-code mt-2">
                            <pre tabindex="0" aria-label="Contoh JSON {{ $name }}"><code>{{ json_encode($sampleData[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        </div>
                    </details>
                @endif
            </article>
        @endforeach
    </div>
</section>
