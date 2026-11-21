@extends('admin.supplier-margins.layout')

@section('margin-content')
    <x-admin-dashboard.notice tone="warning" role="alert">
        <p class="font-semibold">Harga tidak disimpan</p>
        <p>{{ $message }}</p>
    </x-admin-dashboard.notice>
    <div>
        <x-admin-dashboard.button :href="route('admin.suppliers.margins.index', $query)" variant="primary">Muat ulang daftar margin</x-admin-dashboard.button>
    </div>
@endsection
