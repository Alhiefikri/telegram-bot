"""Existing ChatGPT login guide copy from the storefront presentation contract."""

PREPARATION = (
    ('Email login', 'Alamat Outlook atau iCloud yang dikirim oleh bot. Salin persis tanpa spasi.'),
    ('Password', 'Password email/akun. Perhatikan huruf besar, angka, dan simbol.'),
    ('MFA Secret', 'Kunci penyandingan untuk ditambahkan ke Google Authenticator. Ini bukan kode 6 digit.'),
    ('Google Authenticator', 'Aplikasi HP yang menghasilkan kode 6 digit untuk verifikasi ChatGPT.'),
)

QUICK_FLOW = ('Email Outlook/iCloud', 'Password akun', 'Tambah MFA Secret',
              'Masukkan kode 6 digit', 'Ganti password + MFA')

STEPS = (
    {'number': '01', 'kicker': 'Login ChatGPT', 'title': 'Masukkan email Outlook/iCloud di kolom email.',
     'image': 'chatgpt-email-step.png', 'alt': 'Screenshot halaman Log in or sign up ChatGPT dengan kolom email dan tombol Continue ditandai',
     'caption': 'Pilih jalur email manual. Jangan memakai Continue with Google, Apple, atau phone kecuali data dari bot memang meminta itu.',
     'body': 'Buka halaman ChatGPT, pilih Log in atau Sign up, lalu isi alamat email yang diberikan. Setelah email benar, tekan Continue.',
     'points': ('Gunakan email yang dikirim bot, misalnya nama@outlook.com atau nama@icloud.com.', 'Pastikan tidak ada spasi di depan/belakang email saat menempelkan teks.', 'Tekan Continue satu kali dan tunggu halaman password muncul.'),
     'watch': 'Kalau tidak bisa lanjut, biasanya email salah ketik, domain salah, atau ada spasi tersembunyi.'},
    {'number': '02', 'kicker': 'Password', 'title': 'Isi password persis seperti data yang dikirim.',
     'image': 'chatgpt-password-step.png', 'alt': 'Screenshot halaman password ChatGPT dengan email, password, dan tombol Continue ditandai',
     'caption': 'Urutannya: cek email di atas, isi password, lalu Continue.',
     'body': 'Halaman berikutnya meminta password akun. Masukkan password dengan teliti karena huruf besar/kecil, angka, dan simbol berpengaruh.',
     'points': ('Cocokkan email yang tampil di bagian atas halaman password.', 'Isi password tanpa mengubah karakter apa pun.', 'Gunakan tombol lihat password jika tersedia sebelum menekan Continue.'),
     'watch': 'Jangan menebak terlalu banyak kali. Jika gagal, ulangi dari salin data awal agar akun tidak terkena pembatasan sementara.'},
    {'number': '03', 'kicker': 'Google Authenticator', 'title': 'Tambahkan MFA Secret ke Google Authenticator.',
     'image': 'google-authenticator-setup.png', 'alt': 'Screenshot Google Authenticator untuk memasukkan nama kode, MFA Secret, jenis berbasis waktu, dan tombol Tambahkan',
     'caption': 'MFA Secret ditempel di kolom Kunci Anda. Jenis kunci harus Berbasis waktu.',
     'body': 'Buka Google Authenticator di HP, tekan tombol tambah, lalu pilih opsi memasukkan kunci penyiapan/kode penyandingan.',
     'points': ('Isi Nama kode, misalnya ChatGPT atau Lhie Store agar mudah dikenali.', 'Tempel MFA Secret ke kolom Kunci Anda.', 'Pastikan Jenis kunci = Berbasis waktu, lalu tekan Tambahkan.'),
     'watch': 'MFA Secret hanya dipakai di Google Authenticator. Jangan memasukkan MFA Secret ke halaman ChatGPT.'},
    {'number': '04', 'kicker': 'Kode MFA', 'title': 'Ambil kode 6 digit terbaru dari Authenticator.',
     'image': 'google-authenticator-code.png', 'alt': 'Contoh kartu Google Authenticator yang menampilkan kode 6 digit berubah otomatis',
     'caption': 'Kode 6 digit berubah otomatis setiap beberapa detik. Pakai kode yang waktunya masih cukup.',
     'body': 'Setelah MFA Secret berhasil ditambahkan, Google Authenticator akan menampilkan kode 6 digit. Inilah kode yang dipakai untuk verifikasi login.',
     'points': ('Cari nama kode yang baru dibuat di Google Authenticator.', 'Jika timer hampir habis, tunggu kode baru agar tidak ditolak.', 'Salin atau ketik 6 digit angka saja, tanpa spasi dan tanpa teks tambahan.'),
     'watch': 'Kode 6 digit berbeda dari MFA Secret. Kode ini sementara; MFA Secret adalah kunci penyandingan.'},
    {'number': '05', 'kicker': 'Verifikasi ChatGPT', 'title': 'Masukkan kode 6 digit ke halaman verifikasi.',
     'image': 'chatgpt-mfa-code-step.png', 'alt': 'Screenshot halaman Verify your identity ChatGPT dengan kolom kode 6 digit dan tombol Continue ditandai',
     'caption': 'Isi kolom One-time code dengan kode terbaru, lalu tekan Continue.',
     'body': 'Kembali ke halaman ChatGPT yang meminta one-time password, masukkan kode 6 digit dari Google Authenticator, kemudian tekan Continue.',
     'points': ('Masukkan angka terbaru dari Authenticator, bukan kode lama.', 'Tekan Continue setelah semua 6 digit terisi.', 'Jika ditolak, tunggu kode baru dan ulangi tanpa mengubah MFA Secret.'),
     'watch': 'Kode yang sudah kedaluwarsa sering terlihat benar, tetapi tetap ditolak. Tunggu siklus kode baru jika ragu.'},
    {'number': '06', 'kicker': 'Keamanan akun', 'title': 'Setelah masuk, ganti password dan atur ulang MFA.',
     'image': 'chatgpt-security-mfa.png', 'alt': 'Screenshot menu Security and login dengan password dan Authenticator app ditandai',
     'caption': 'Prioritas setelah berhasil masuk: password baru dulu, lalu MFA baru di perangkat kamu.',
     'body': 'Login berhasil belum selesai. Amankan akun agar aksesnya berada di perangkat kamu sendiri dan tidak bergantung pada data awal dari bot.',
     'points': ('Buka menu Security and login.', 'Ganti password dengan password baru yang kuat.', 'Masuk ke Multi-factor authentication, tambah MFA baru, lalu hapus metode lama jika yang baru sudah aktif.'),
     'watch': 'Simpan password dan metode MFA baru di tempat aman. Jangan kirim password/MFA ke ruang publik.'},
)

TROUBLESHOOTING = (
    ('Email tidak lanjut', 'Cek typo, domain email, dan spasi tersembunyi. Salin ulang dari data bot bila perlu.'),
    ('Password salah', 'Periksa huruf besar/kecil, simbol, angka, dan jangan menambahkan spasi saat menempel.'),
    ('Kode MFA ditolak', 'Gunakan kode 6 digit terbaru dari Google Authenticator, bukan MFA Secret dan bukan kode lama.'),
    ('Kode tidak muncul', 'Tambahkan ulang MFA Secret di Google Authenticator dengan jenis kunci Berbasis waktu.'),
)

CHECKLIST = ('Password sudah diganti dengan password baru.', 'MFA/Authenticator sudah aktif di perangkat kamu sendiri.',
             'Kode pemulihan atau data penting sudah disimpan dengan aman.', 'Akun sudah logout dari perangkat yang tidak digunakan.')
