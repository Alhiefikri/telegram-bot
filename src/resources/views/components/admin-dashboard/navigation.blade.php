@props([
    'groups' => [],
    'label' => 'Navigasi admin',
    'idPrefix' => 'admin-navigation',
])

<nav {{ $attributes->merge(['aria-label' => $label, 'x-on:click' => 'handleSidebarNavigation($event)', 'x-on:auxclick' => 'handleSidebarNavigation($event)']) }}>
    @foreach ($groups as $group)
        @php
            $items = array_values(array_filter($group['items'] ?? [], static fn (array $item): bool => (bool) ($item['visible'] ?? true)));
        @endphp
        @if ($items !== [])
            <section class="not-first:mt-6" aria-labelledby="{{ $idPrefix }}-{{ $group['id'] }}">
                <h2 id="{{ $idPrefix }}-{{ $group['id'] }}" class="px-3 text-xs font-semibold text-admin-muted">
                    {{ $group['label'] }}
                </h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($items as $item)
                        <li>
                            <x-admin-dashboard.navigation-item
                                :href="$item['href']"
                                :label="$item['label']"
                                :icon="$item['icon']"
                                :active="$item['active'] ?? false"
                                :badge="$item['badge'] ?? null"
                            />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
</nav>
