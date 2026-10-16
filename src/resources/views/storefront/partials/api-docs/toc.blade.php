@php
    /**
     * Section list shared by the mobile disclosure and the sticky desktop column.
     * Operation links carry the same filter haystack as their article so the list
     * and the filtered content never disagree.
     */
@endphp
<ol class="grid gap-1">
    @foreach ($guideSections as $section)
        <li>
            <a href="#{{ $section['id'] }}">
                <span data-api-docs-block="id">{{ $section['title']['id'] }}</span>
                <span data-api-docs-block="en">{{ $section['title']['en'] }}</span>
            </a>
        </li>
    @endforeach

    <li>
        <a href="#endpoint">
            <span data-api-docs-block="id">Endpoint</span>
            <span data-api-docs-block="en">Endpoints</span>
        </a>
    </li>

    @foreach ($operations as $operation)
        <li class="pl-3">
            <a
                href="#endpoint-{{ strtolower($operation['id']) }}"
                data-api-docs-search-item
                data-api-docs-search="{{ $tables->searchText($operation) }}"
            >
                <span class="font-mono text-[11px] font-black text-[var(--sf-accent)]">{{ $operation['method'] }}</span>
                <span class="font-mono text-[11px] break-all">{{ $operation['path'] }}</span>
            </a>
        </li>
    @endforeach

    <li>
        <a href="#skema">
            <span data-api-docs-block="id">Skema data</span>
            <span data-api-docs-block="en">Data schemas</span>
        </a>
    </li>
</ol>
