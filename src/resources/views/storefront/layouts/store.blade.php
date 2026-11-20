<!DOCTYPE html>
<html lang="{{ $metadata->htmlLocale() }}" class="scroll-smooth bg-[var(--sf-paper)]">
<head>
    @include('storefront.partials.head', ['metadata' => $metadata])
</head>
<body class="relative overflow-x-hidden text-[var(--sf-ink)] antialiased selection:bg-[var(--sf-ink)] selection:text-white motion-reduce:animate-none">
    <div class="storefront-shell relative z-10 flex min-h-screen flex-col justify-between overflow-x-clip @yield('shell-class')">
        @include('storefront.partials.header')
        @yield('content')
        @include('storefront.partials.footer')
    </div>
    @yield('overlays')
</body>
</html>
