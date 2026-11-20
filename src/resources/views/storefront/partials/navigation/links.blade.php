@foreach ($navLinks as $link)
    <a href="{{ $link['href'] }}" class="home-catalog-link inline-flex min-h-11 items-center rounded-lg px-1.5 text-sm font-semibold">{{ $link['label'] }}</a>
@endforeach
