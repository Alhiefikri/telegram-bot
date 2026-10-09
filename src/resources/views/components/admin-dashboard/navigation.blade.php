@props([
    'groups' => [],
    'label' => 'Navigasi admin',
])

<nav {{ $attributes->merge(['aria-label' => $label]) }}>
    @foreach ($groups as $group)
        @php($items = array_values(array_filter($group['items'] ?? [], static fn (array $item): bool => (bool) ($item['visible'] ?? true))))
        @if ($items !== [])
            <section class="not-first:mt-6" aria-labelledby="admin-navigation-{{ $group['id'] }}">
                <h2 id="admin-navigation-{{ $group['id'] }}" class="px-3 text-xs font-semibold uppercase tracking-wider text-admin-muted">
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
