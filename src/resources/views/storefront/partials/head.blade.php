<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDescription }}">
<link rel="canonical" href="{{ $canonicalUrl }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $brand }}">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:image" content="{{ Vite::asset('resources/images/lhie-store-logo-512.png') }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $pageTitle }}">
<meta name="twitter:description" content="{{ $pageDescription }}">
<meta name="twitter:image" content="{{ Vite::asset('resources/images/lhie-store-logo-512.png') }}">
<link rel="icon" type="image/png" sizes="256x256" href="{{ Vite::asset('resources/images/chibi-favicon-256.png') }}">
<link rel="icon" type="image/png" sizes="64x64" href="{{ Vite::asset('resources/images/chibi-favicon-64.png') }}">
<link rel="apple-touch-icon" href="{{ Vite::asset('resources/images/chibi-favicon-256.png') }}">
@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400..800;1,400..800&family=Space+Grotesk:wght@400..700&display=swap" rel="stylesheet">
