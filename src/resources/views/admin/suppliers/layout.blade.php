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
<body class="admin-page min-h-screen bg-neo-paper text-neo-ink">
    <main class="supplier-workspace mx-auto grid max-w-6xl gap-6 p-4 pb-12 sm:p-6">
        <header class="supplier-panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b-2 border-neo-ink p-4 sm:p-5">
                <a class="supplier-button" href="{{ route('admin.dashboard', ['tab' => 'suppliers']) }}"><span aria-hidden="true">←</span> Supplier dashboard</a>
                <div class="flex flex-wrap items-center gap-3">
                    <a class="supplier-button" href="{{ route('admin.suppliers.purchases') }}">Monitor pembelian</a>
                    <a class="supplier-button" href="{{ route('admin.suppliers.warranty') }}">Klaim &amp; refund</a>
                    <a class="supplier-button" href="{{ route('password.confirm') }}">Konfirmasi password</a>
                    <x-theme-toggle />
                </div>
            </div>
            <div class="bg-neo-purple-soft p-5 sm:p-6"><p class="supplier-eyebrow">Supplier Reseller · Akses privat</p><h1 class="mt-2 break-words text-2xl font-black sm:text-3xl">@yield('title')</h1></div>
        </header>
        @if(session('status'))<p role="status" class="supplier-panel bg-neo-success-soft p-4 font-bold text-neo-success-text">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="supplier-alert"><p class="font-bold">Periksa kembali isian berikut:</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
</body>
</html>
