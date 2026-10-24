# Cover family API — inspeksi dan prompt

Status: belum generate; belum ada preview atau persetujuan pemasangan. Tidak ada perubahan production, source aplikasi, atau GitHub Actions. File ini adalah hasil persiapan, bukan bukti artwork selesai.

## Identitas production yang diperiksa

SSH menggunakan verifikasi host key normal: `ssh -p 2468 alhiefikri@43.134.177.28`.
Host `VM-0-7-ubuntu`, akun `alhiefikri`, waktu pemeriksaan awal `2026-10-24T15:13:30+07:00`.
Repo `/home/alhiefikri/github/telegram-bot`, HEAD `b9ae6f424d9bb9bb5f2cb6ddf732efce5cab5e30`.
Container app `telegram-bot-prod-app-1`, working directory `/var/www/html`, image ID `sha256:ac911383ecaf8f13752d85ce2a3d8346cf5cfeae74617f2c0f8942767d7991eb`.
Container DB `telegram-bot-prod-db-1`, PostgreSQL 16. Semua container yang terlihat berstatus healthy.
URL aplikasi dari konfigurasi: `https://lhiestore.web.id`.

Data dibaca melalui bootstrap aplikasi dalam transaksi PostgreSQL `SET TRANSACTION READ ONLY`, lalu rollback. Query memakai allowlist kolom tanpa secret atau kredensial supplier.

| Family | ID | Slug | Anggota (ID) |
| --- | --- | --- | --- |
| GPT API | 16 | gpt-api | 36, 37, 38, 39 |
| GPT + Claude API | 17 | gpt-claude-api | 44, 45, 46, 47 |
| China Models API | 18 | china-models-api | 48, 49, 50 |

Ketiga family memiliki `image_path = null` dan `telegram_image_path = null`.
Seluruh 11 anggota memiliki `image_path`, `telegram_image_path`, dan `telegram_channel_image_path` null. Anggota aktif, numeric stock dan reserved stock nol saat inspeksi; ini bukan pemeriksaan kapasitas supplier.
Family CLAUDE API KEY tidak diperbarui. Tidak ada penulisan DB atau aset production.

## Prasyarat generasi yang belum terpenuhi

- Tool built-in `image_gen.imagegen` tersedia, tetapi schema tidak menyediakan pilihan model atau menyatakan identitas model backend. Keberadaan tool ini belum membuktikan GPT Image 2 tersedia.
- Variabel `OPENAI_API_KEY` terdeteksi ada tanpa membaca/menampilkan nilainya. Ini belum membuktikan asal kredensial, endpoint resmi, atau entitlement GPT Image 2. Tidak ada panggilan image API atau penggunaan kredensial supplier.
- `antislop`, `antislop-ui`, dan `antislop-copywriting` tidak ditemukan dalam pencarian `SKILL.md` pada root skill lokal `.agents`, `.codex/skills`, cache plugin `.codex/plugins`, dan skill repo. Tidak ada instalasi atau perubahan preferensi global.
- Karena itu, tidak ada klaim skill antislop DURING sudah dijalankan. Konsep di bawah mengikuti batas visual eksplisit pengguna dan DESIGN.md; perlu ditinjau kembali dengan skill yang diminta setelah lokasinya tersedia.

Model yang benar-benar digunakan: tidak ada. Prompt di bawah belum dieksekusi.

## Bukti jalur aplikasi

Tujuh file utama berikut memiliki SHA-256 identik antara source lokal dan container app production:
`ProductImageOptimizer`, `ProductFamilyManagementService`, `TelegramProductNotificationService`, `StockNotificationDeliveryPipeline`, `StorefrontProductPresenter`, `StorefrontCatalogProductPresenter`, dan `StorefrontCacheInvalidationObserver`.

### Penyimpanan, audit, cache

- `src/app/Http/Requests/Admin/AdminProductFamilyFormRequest.php`: JPG/JPEG/PNG/WebP; maksimum 5.120 KiB; dimensi setiap sisi maksimum 6.000 px.
- `src/app/Services/Admin/ProductImageOptimizer.php`: `storeFamily()` → disk public `product-families/<uuid>.webp`; `storeTelegramFamily()` → `telegram-product-families/<uuid>.webp`. Batas sisi terpanjang 1.600 px, WebP quality 84, rasio dipertahankan, tidak di-crop. Capability GD production dilaporkan tanpa gap.
- Satu master square dapat menghasilkan dua derivatif melalui dua method tersebut, tetap di level family.
- `ProductFamilyManagementService::update()` menerima subset field, mengunci family dalam transaksi, mencatat `product_family.updated` melalui `AuditLogger`. Gunakan hanya dua field gambar agar field lain tidak tersentuh, dengan actor admin yang benar.
- `AppServiceProvider` mendaftarkan `StorefrontCacheInvalidationObserver` untuk ProductFamily. Observer berjalan setelah commit dan menginvalidasi storefront response cache serta Telegram catalog projection cache.
- Controller update family menghapus aset lama sesudah update. Untuk menjaga rollback, jalur service + optimizer yang tersedia lebih sesuai: simpan referensi lama, store derivatif baru, update hanya field gambar melalui service, dan jangan panggil penghapusan aset lama. Jangan menggunakan `replaceFamily()` karena method itu juga menghapus aset lama.

### Storefront: fallback belum lengkap

- `CatalogBrowse` memetakan gambar family dari `family.image_path`; `StorefrontCatalogFamily::fromReadModel()` meneruskannya.
- `storefront/components/family-card.blade.php` dan `storefront/family.blade.php` memakai `family.imageUrl`.
- **Detail produk belum mewarisi gambar family.** `StorefrontProductPresenter::imageUrl()` hanya membaca `product.image_path`, lalu default cover/placeholder. Method yang sama terkonfirmasi dalam container production.
- `StorefrontCatalogProductPresenter::imageUrl()` juga hanya membaca imagePath/imageUrl produk. Read model saat ini memetakan nilai dari Product; tidak membawa fallback gambar family.
- `StorefrontCartPageBuilder` sudah memiliki fallback `product.image_path ?? product.family.image_path`; ini tidak membuktikan detail produk ikut mewarisi.
- View product-cover memakai square dengan `object-contain` untuk kartu/featured dan pembesaran hover sekitar 2%. Crop-safe margin tetap diperlukan untuk penggunaan lain.

Perubahan kode minimum yang perlu persetujuan terpisah: tambahkan resolusi gambar efektif dengan urutan product override → family image → default pada detail dan alur katalog produk. Muat kolom/relasi family secara batch pada boundary data agar tidak menambah query per kartu; pertahankan field gambar mentah untuk Admin. Tambahkan regresi fallback, override, family tanpa gambar, serta verifikasi cache setelah update. Deploy hanya melalui jalur resmi setelah diizinkan. Tidak ada patch kode yang dibuat pada sesi ini.

### Restock Telegram

`TelegramProductNotificationService::telegramImagePath()` memilih:

1. product.telegram_channel_image_path
2. product.telegram_image_path
3. family.telegram_image_path
4. product.image_path
5. family.image_path

`stockAddedDeliveryBatch()` memasukkan pilihan ini pada `photo_path` recipient Store Channel. Recipient customer privat memiliki `photo_path = null`.
`StockNotificationDeliveryPipeline` menyimpan pilihan dalam recipient, membaca file melalui `ProductImageOptimizer::telegramPhoto()`, lalu menyiapkan `sendPhoto` multipart dengan caption. File tidak tersedia atau caption lebih dari 1.024 karakter menghasilkan `sendMessage`. `telegramPhoto()` mengizinkan kedua direktori family yang disebut di atas.
Pilihan path disnapshot saat materialisasi recipient; pemasangan gambar tidak otomatis mengganti notifikasi lama yang sudah dimaterialisasi.
Test yang tersedia: `ProductInventoryRaceTest::test_restock_channel_photo_prefers_product_channel_image_then_existing_media_order`, serta test fallback file hilang. Test ini dibaca, belum dijalankan pada sesi ini.
Tidak ada event restock, pembelian, panggilan supplier, broadcast, atau pesan uji yang dipicu. Verifikasi ini adalah penelusuran source production, bukan end-to-end Telegram.

## Konsep seri

Mengikuti storefront DESIGN.md: paper `#fffdf8`, ink `#0b1220`, electric blue `#005cff`, yellow `#ffe45c`; display sans geometris menyerupai Space Grotesk. Bentuk memiliki garis tinta dan bayangan offset pendek. Tidak meniru Admin Dashboard.

- **GPT API — indeks bertingkat:** tiga lembar indeks tebal tersusun bertangga, satu kelompok berwarna biru. Menunjukkan satu keluarga dengan beberapa pilihan tanpa memakai simbol perusahaan. Judul besar terpisah dari motif.
- **GPT + Claude API — dua jalur dalam satu organizer:** dua berkas berbeda berdiri di satu organizer terbuka, biru dan kuning, terpisah oleh ruang kosong. Kebersamaan berasal dari wadah akses, bukan logo gabungan atau motif merger.
- **China Models API — rak pilihan:** empat volume abstrak independen dengan profil punggung berbeda tersusun pada rak horizontal pendek. Ragam bentuk menyatakan banyak model independen. Biru dan kuning tetap mengikat seri, dengan sedikit hijau mint untuk variasi; tidak memakai ikon budaya atau bendera.

## Prompt final siap generate — GPT API

```text
Use case: ads-marketing
Asset type: one square raster Product Family cover for Lhie Store, also used as a Telegram Store Channel notification photo.
Create a finished 1024x1024 or larger square editorial illustration. Warm paper background #fffdf8, near-black ink #0b1220, electric blue #005cff, restrained yellow #ffe45c accents. Bold geometric sans-serif display lettering with open counters and generous spacing, in the spirit of Space Grotesk. Clean silhouettes, short crisp offset ink shadows, subtle printed-paper character. No enclosing UI card or interface controls.
Main motif: three thick index sheets arranged as a compact ascending staircase, clearly belonging to one blue collection. Each sheet has a broad solid tab and a simple blank face; no code, no tiny labels. Use an oblique top view so the three separate sheets remain recognizable at thumbnail size. A small yellow tab gives a single focal accent. Keep the motif in the lower central half, the large title above it, and a small store signature below.
Text verbatim: “GPT API”. Set on two spacious lines “GPT” and “API”, dominant and instantly readable at small size. Only additional text: “Lhie Store”, as a discreet signature. Render exact spelling. The artwork represents the GPT family broadly; never rename it Codex.
Keep all text and essential motif inside the central 76% of width and height, with at least 12% safe margin on every edge. Strong contrast, generous negative space, clear at 160x160. Background is opaque. This must feel like playful paper-and-ink storefront artwork, not an admin dashboard.
Do not include corporate logos, partnership badges, supplier names or domains, endpoints, keys, prices, quotas, durations, dates, restock wording, official API claims, guarantees, refunds, endorsements, robots, brains, glowing spheres, generic chips, fake terminals, random code, blue-purple gradients, neon, glass effects, flags, decorative Chinese text, or generic promotional slogans.
```

## Prompt final siap generate — GPT + Claude API

```text
Use case: ads-marketing
Asset type: one square raster Product Family cover for Lhie Store, also used as a Telegram Store Channel notification photo.
Create a finished 1024x1024 or larger square editorial illustration. Match a paper-and-ink series: warm paper #fffdf8, near-black ink #0b1220 outlines, electric blue #005cff, yellow #ffe45c, short crisp offset ink shadows and subtle paper character. Bold geometric sans-serif display typography with open counters, in the spirit of Space Grotesk. No enclosing UI card or interface controls.
Main motif: a single open paper organizer containing two clearly separate upright folios. One folio is blue with a broad rectangular tab; the other is yellow with a curved cutout tab. Both are equal in scale, separated by visible paper-colored space and sharing only the organizer. Use a frontal three-quarter view. The organizer signifies one access point for two model groups. Never fuse the folios, interlock symbols, form a handshake, or suggest an official partnership. Keep this motif in the lower central half.
Text verbatim: “GPT + Claude API”. Use a large two-line title, first line “GPT + Claude”, second line “API”. Keep every word clearly readable with ample spacing; adjust size to fit safely. Only additional text: “Lhie Store”, a discreet signature below the motif. No text on the folios.
Keep all text and essential motif inside the central 76% of width and height, at least 12% safe margin on every edge. Strong contrast, generous negative space, readable at 160x160. Opaque background. Different composition from an ascending stack: two upright folios and one shared organizer.
Do not include corporate logos, joint logos, partnership badges, supplier names or domains, endpoints, keys, prices, quotas, durations, dates, restock wording, official API claims, guarantees, refunds, endorsements, robots, brains, glowing spheres, generic chips, fake terminals, random code, blue-purple gradients, neon, glass effects, flags, decorative Chinese text, or generic promotional slogans.
```

## Prompt final siap generate — China Models API

```text
Use case: ads-marketing
Asset type: one square raster Product Family cover for Lhie Store, also used as a Telegram Store Channel notification photo.
Create a finished 1024x1024 or larger square editorial illustration in a coherent paper-and-ink storefront series. Warm paper #fffdf8, near-black ink #0b1220, electric blue #005cff, yellow #ffe45c, and a small muted mint accent. Bold geometric sans-serif display lettering with open counters and generous spacing, in the spirit of Space Grotesk. Crisp ink outlines, short offset shadows, restrained printed-paper texture. No enclosing UI card or interface controls.
Main motif: a short freestanding horizontal display shelf carrying four independent abstract paper volumes. Each volume has its own clearly different spine silhouette: stepped, rounded, notched, and straight. Keep four clean, large shapes with breathing room, using blue, yellow, paper white, and mint. Show the shelf from an almost frontal view with slight depth. The independent volumes represent a selection of distinct model families such as Kimi, Qwen, DeepSeek and GLM, not one company; do not write these names or draw their logos. No organizer enclosing all four, no merging or corporate emblem. Place the shelf across the lower central half.
Text verbatim: “China Models API”. Set on three strong lines “China”, “Models”, “API”, with a clear readable hierarchy above the shelf. Only additional text: “Lhie Store”, as a discreet signature below. Keep exact spelling.
Keep all text and essential motif inside the central 76% of width and height, with at least 12% safe margin on every edge. Strong contrast, generous negative space, readable at 160x160. Opaque background. Make the horizontal four-volume rhythm distinct from a stepped sheet stack and a two-folio organizer.
Do not include flags, maps, national symbols, cultural stereotypes, decorative Chinese characters, corporate logos, partnership badges, supplier names or domains, endpoints, keys, prices, quotas, durations, dates, restock wording, official API claims, guarantees, refunds, endorsements, robots, brains, glowing spheres, generic chips, fake terminals, random code, blue-purple gradients, neon, glass effects, or generic promotional slogans.
```

## Acceptance dan pemasangan sesudah prasyarat terpenuhi

1. Jalankan skill antislop DURING yang diminta dan pastikan akses model GPT Image 2 serta asal kredensial bila melalui API. Simpan model yang benar-benar digunakan, exact prompt, master, dan hash tiap artwork.
2. Tampilkan tiga gambar terpisah; periksa file asli, ejaan, detail, kemiripan seri, thumbnail 160 px, safe crop, dan mobile. Tunggu persetujuan preview sebelum upload production.
3. Selesaikan persetujuan terpisah untuk perubahan fallback kode dan deployment resmi. Jangan menyiasati dengan menyalin gambar ke produk.
4. Tepat sebelum mutasi, ambil snapshot referensi lama dan fingerprint field non-gambar seluruh family/anggota serta supplier mapping tanpa credential. Harga, stock, status aktif, deskripsi, membership, dan mapping tidak boleh berubah akibat operasi ini. Perubahan stock alami oleh transaksi pelanggan perlu dibedakan dari operasi gambar.
5. Gunakan optimizer dan management service yang sudah ada, actor admin yang benar, transaksi dan audit. Simpan dua derivatif per family. Pertahankan file lama.
6. Bandingkan hasil dengan snapshot, periksa audit yang hanya memuat field gambar, file/ukuran/MIME dan invalidasi cache. Browser harus membuktikan family, detail anggota fallback, thumbnail dan mobile. Uji pemilihan foto tanpa membuat event/notifikasi.
7. Rollback sesudah pemasangan: update hanya `image_path` dan `telegram_image_path` ke nilai snapshot lewat management service dengan audit dan invalidasi setelah commit. Snapshot awal saat inspeksi ini null untuk keduanya pada semua family, tetapi wajib diambil ulang saat pemasangan. Biarkan aset baru tersimpan sampai referensi dan recipient snapshot yang memakainya dipastikan aman. Tidak ada rollback yang perlu dilakukan sekarang.

Belum ada aset, lokasi derivatif aktual, bukti browser sesudah pemasangan, maupun verifikasi Telegram end-to-end. Tidak ada unggahan atau perubahan family yang telah dilakukan.
