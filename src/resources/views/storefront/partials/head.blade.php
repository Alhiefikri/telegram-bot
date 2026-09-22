<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
@if (!($isPublicHomepage ?? false))
<meta name="csrf-token" content="{{ csrf_token() }}">
@endif
<title>{{ $metadata->title }}</title>
<meta name="description" content="{{ $metadata->description }}">
<meta name="robots" content="{{ $metadata->robots }}">
@if ($metadata->canonicalUrl)
<link rel="canonical" href="{{ $metadata->canonicalUrl }}">
@endif
<meta property="og:type" content="{{ $metadata->ogType }}">
<meta property="og:site_name" content="{{ $brand }}">
<meta property="og:locale" content="{{ $metadata->locale }}">
<meta property="og:title" content="{{ $metadata->title }}">
<meta property="og:description" content="{{ $metadata->description }}">
@if ($metadata->canonicalUrl)
<meta property="og:url" content="{{ $metadata->canonicalUrl }}">
@endif
<meta property="og:image" content="{{ $metadata->ogImage }}">
<meta property="og:image:alt" content="{{ $metadata->ogImageAlt }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $metadata->title }}">
<meta name="twitter:description" content="{{ $metadata->description }}">
<meta name="twitter:image" content="{{ $metadata->ogImage }}">
<meta name="twitter:image:alt" content="{{ $metadata->ogImageAlt }}">
@foreach ($metadata->jsonLd as $jsonLd)
<script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
@endforeach
@include('partials.theme-init')
<link rel="icon" type="image/png" sizes="256x256" href="{{ Vite::asset('resources/images/chibi-favicon-256.png') }}">
<link rel="icon" type="image/png" sizes="64x64" href="{{ Vite::asset('resources/images/chibi-favicon-64.png') }}">
<link rel="apple-touch-icon" href="{{ Vite::asset('resources/images/chibi-favicon-256.png') }}">
@fonts
@if ($isPublicHomepage ?? false)
@vite(['resources/css/storefront.css', 'resources/js/storefront-entry.js'])
@else
@vite(['resources/css/app.css', 'resources/js/app.js'])
@endif
