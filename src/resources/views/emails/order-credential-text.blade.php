Data akun pesanan {{ $invoiceNumber }}

Pembayaran pesanan ini sudah terverifikasi. Data akun untuk pesanan Anda ada di email ini. Simpan email ini di tempat aman.

@foreach ($groups as $group)
{{ $group['product_name'] }}
@foreach ($group['accounts'] as $account)
{{ $account['label'] }}
@if ($account['kind'] === 'legacy')
{{ $account['text'] }}
@if ($account['parts'] !== null)
email : {{ $account['parts']['email'] }}
password : {{ $account['parts']['password'] }}
@if (filled($account['parts']['mfa'] ?? null))
mfa : {{ $account['parts']['mfa'] }}
@endif
@endif
@else
email : {{ $account['email'] }}
password : {{ $account['password'] }}
@if ($account['access'] !== null)
{{ strtolower($account['access']['label']) }} : {{ $account['access']['value'] }}
@if (filled($account['access']['url'] ?? null))
link : {{ $account['access']['url'] }}
@endif
@endif
@endif
@if (filled($account['tutorial'] ?? null))
instruksi akun : {{ $account['tutorial'] }}
@endif

@endforeach
@if (filled($group['delivery_note'] ?? null))
instruksi produk : {{ $group['delivery_note'] }}
@endif
@if (filled($group['usage_guide']['url'] ?? null))
{{ $group['usage_guide']['title'] }} : {{ $group['usage_guide']['url'] }}
@endif

@endforeach
@if ($groups !== [] && filled($defaultInstruction))
Instruksi penggunaan aman: {{ $defaultInstruction }}
@endif
@if ($usageGuides !== [])
Panduan produk:
@foreach ($usageGuides as $guide)
- {{ $guide['title'] }} : {{ $guide['url'] }}
@endforeach
@endif

Halaman invoice: {{ $invoiceUrl }}
Akses pesanan (nomor invoice + Recovery Key): {{ $orderAccessUrl }}

Jangan bagikan data akun, email ini, atau Recovery Key kepada siapa pun, termasuk pihak yang mengaku support. Ganti password akun setelah login pertama bila produk mendukungnya.
