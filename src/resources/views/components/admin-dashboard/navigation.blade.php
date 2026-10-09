@props([
    'groups' => [],
    'label' => 'Navigasi admin',
    'enhanceTabs' => false,
])

<nav {{ $attributes->merge(['aria-label' => $label]) }}>
    @foreach ($groups as $group)
        @php
            $items = array_values(array_filter($group['items'] ?? [], static fn (array $item): bool => (bool) ($item['visible'] ?? true)));
        @endphp
        @if ($items !== [])
            <section class="not-first:mt-6" aria-labelledby="admin-navigation-{{ $group['id'] }}">
                <h2 id="admin-navigation-{{ $group['id'] }}" class="px-3 text-xs font-semibold text-admin-muted">
                    {{ $group['label'] }}
                </h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($items as $item)
                        <li>
                            @php
                                $linkAttributes = [];
                                if ($enhanceTabs && ($item['tab'] ?? null) !== null) {
                                    $linkAttributes['x-on:click.prevent'] = 'goToTab('.\Illuminate\Support\Js::from($item['tab']).')';
                                }
                            @endphp
                            <x-admin-dashboard.navigation-item
                                :href="$item['href']"
                                :label="$item['label']"
                                :icon="$item['icon']"
                                :active="$item['active'] ?? false"
                                :badge="$item['badge'] ?? null"
                                :attributes="new \Illuminate\View\ComponentAttributeBag($linkAttributes)"
                            />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
</nav>
