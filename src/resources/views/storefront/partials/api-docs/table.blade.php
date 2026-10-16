@props([
    'headers' => [],
    'rows' => [],
    'caption' => 'Tabel dokumentasi',
    'monoColumns' => [],
])

<div class="overflow-x-auto" role="region" aria-label="{{ $caption }}" tabindex="0">
    <table class="api-docs-table">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th scope="col">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $index => $cell)
                        <td @class(['font-mono text-xs font-bold' => in_array($index, $monoColumns, true)])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
