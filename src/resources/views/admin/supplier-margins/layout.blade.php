<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Margin Supplier · Admin LHIE STORE</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page min-h-screen bg-admin-paper text-admin-ink">
    <x-admin-dashboard.linked-shell active-tab="suppliers" active-navigation-id="supplier-margins" title="Margin Supplier">
        <div class="grid min-w-0 gap-5">
            @if(session('status'))
                <x-admin-dashboard.notice tone="success" role="status">{{ session('status') }}</x-admin-dashboard.notice>
            @endif
            @if($errors->any())
                <x-admin-dashboard.notice tone="danger" role="alert">
                    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                </x-admin-dashboard.notice>
            @endif
            @yield('margin-content')
        </div>
    </x-admin-dashboard.linked-shell>
</body>
</html>
