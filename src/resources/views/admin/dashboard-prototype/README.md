# Dashboard UI Prototype (THROWAWAY)

Prototype mandiri untuk membandingkan tiga struktur dashboard. Tidak terhubung ke Laravel, database, API, akun, atau storage browser. Semua angka adalah data fiktif.

## Jalankan

Dari root repository:

```bash
python -m http.server 8765 --bind 127.0.0.1 --directory src/resources/views/admin/dashboard-prototype
```

Buka `http://127.0.0.1:8765/?variant=A`.

## Variant

- `A` — Classic: sidebar permanen, ringkasan dan tabel.
- `B` — Workspace: rail ikon + tab horizontal, paling padat untuk operasional.
- `C` — Command: prioritas tindakan, cocok bila admin lebih sering menangani masalah daripada membaca KPI.

Ganti desain dengan tombol panah di bawah, keyboard `←` / `→`, atau URL `?variant=A`, `?variant=B`, `?variant=C`.

## Interaksi simulasi

Navigasi antarbagian, pencarian/filter pesanan, detail invoice, salin link contoh, dan menu mobile hanya mengubah state in-memory. Tidak ada aksi nyata.

## Pertanyaan yang perlu dijawab

1. Sidebar permanen terasa lebih cepat daripada menu sekarang?
2. Informasi utama lebih mudah ditemukan pada A, B, atau C?
3. Apakah gaya visual existing perlu dipertahankan, atau struktur C lebih sesuai untuk kerja harian?
