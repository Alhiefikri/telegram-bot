@props([
    'title' => 'Belum ada data',
    'description' => 'Data akan muncul di sini setelah transaksi masuk.',
])

<div {{ $attributes->class('min-w-0 rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 p-6 text-center') }}>
    <p class="break-words text-sm font-semibold text-slate-900">{{ $title }}</p>
    <p class="mt-1 break-words text-sm leading-6 text-slate-600">{{ $description }}</p>
</div>
