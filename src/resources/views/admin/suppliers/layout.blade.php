<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title') · Admin LHIE STORE</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
    <x-admin-dashboard.linked-shell active-tab="suppliers" title="Supplier">
        @if(session('status'))
            <x-admin-dashboard.notice tone="success" class="mb-5 text-sm font-semibold">{{ session('status') }}</x-admin-dashboard.notice>
        @endif
        @if($errors->any())
            <x-admin-dashboard.notice tone="danger" class="mb-5">
                <p class="font-semibold">Periksa kembali isian berikut:</p>
                <ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-admin-dashboard.notice>
        @endif
        @yield('content')
    </x-admin-dashboard.linked-shell>
</body>
</html>
