<?php

namespace App\Services\Telegram;

final class TelegramTranslationCatalog
{
    /**
     * @var array<string, array{en: string, id?: string, ms?: string}>
     */
    private const TRANSLATIONS = [
        'translation.missing' => [
            'en' => 'Translation unavailable.',
        ],
        'language.picker.prompt' => [
            'id' => '🌐 Pilih bahasa',
            'en' => '🌐 Choose your language',
            'ms' => '🌐 Pilih bahasa',
        ],
        'welcome.default_name' => [
            'id' => 'Kak',
            'en' => 'there',
            'ms' => 'anda',
        ],
        'welcome.greeting.morning' => [
            'id' => 'Selamat pagi',
            'en' => 'Good morning',
            'ms' => 'Selamat pagi',
        ],
        'welcome.greeting.afternoon' => [
            'id' => 'Selamat siang',
            'en' => 'Good afternoon',
            'ms' => 'Selamat tengah hari',
        ],
        'welcome.greeting.evening' => [
            'id' => 'Selamat sore',
            'en' => 'Good evening',
            'ms' => 'Selamat petang',
        ],
        'welcome.greeting.night' => [
            'id' => 'Selamat malam',
            'en' => 'Good evening',
            'ms' => 'Selamat malam',
        ],
        'welcome.caption' => [
            'id' => ":greeting, :first_name — selamat datang di LHIE STORE\n━━━━━━━━━━━━━━\nTempat belanja produk digital yang simpel, cepat, dan langsung dari Telegram.\n\n🛍️ Lihat produk\n💳 Bayar via QRIS / transfer\n📦 Cek pesanan kapan aja\n⚡ Akun dikirim otomatis setelah pembayaran beres\n\nMau mulai dari mana nih?",
            'en' => ":greeting, :first_name — Welcome to LHIE STORE\n━━━━━━━━━━━━━━\nA simple, fast way to shop for digital products directly from Telegram.\n\n🛍️ Browse products\n💳 Pay by QRIS / transfer\n📦 Check your orders anytime\n⚡ Accounts are delivered automatically after payment is confirmed\n\nWhere would you like to start?",
            'ms' => ":greeting, :first_name — selamat datang ke LHIE STORE\n━━━━━━━━━━━━━━\nCara mudah dan pantas untuk membeli produk digital terus dari Telegram.\n\n🛍️ Lihat produk\n💳 Bayar melalui QRIS / pindahan bank\n📦 Semak pesanan bila-bila masa\n⚡ Akaun dihantar secara automatik selepas bayaran disahkan\n\nAnda mahu mula dari mana?",
        ],
        'welcome.action.products' => [
            'id' => '🛍️ Lihat Produk',
            'en' => '🛍️ Browse Products',
            'ms' => '🛍️ Lihat Produk',
        ],
        'welcome.action.orders' => [
            'id' => '📦 Cek Pesanan',
            'en' => '📦 Check Orders',
            'ms' => '📦 Semak Pesanan',
        ],
        'menu.title' => [
            'id' => "☰ Menu utama\nMau ngapain dulu? Pilih tombol di bawah biar sat-set.",
            'en' => "☰ Main menu\nWhat would you like to do?",
            'ms' => "☰ Menu utama\nApa yang anda mahu lakukan? Pilih butang di bawah.",
        ],
        'menu.maintenance' => [
            'id' => '⏸️ Checkout sedang maintenance. Produk dan keranjang tetap bisa diakses; Order baru sementara ditahan.',
            'en' => '⏸️ Checkout is under maintenance. Products and the cart remain available; new Orders are temporarily paused.',
            'ms' => '⏸️ Checkout sedang diselenggara. Produk dan troli masih boleh diakses; pesanan baharu ditangguhkan buat sementara.',
        ],
        'maintenance.admin_note' => [
            'id' => "\n\nCatatan admin:\n:note",
            'en' => "\n\nAdmin note:\n:note",
            'ms' => "\n\nNota admin:\n:note",
        ],
        'menu.products' => [
            'id' => '🛍️ Lihat Produk',
            'en' => '🛍️ Browse Products',
            'ms' => '🛍️ Lihat Produk',
        ],
        'menu.orders' => [
            'id' => '📦 Pesanan',
            'en' => '📦 Orders',
            'ms' => '📦 Pesanan',
        ],
        'menu.support' => [
            'id' => '💬 Support',
            'en' => '💬 Support',
            'ms' => '💬 Sokongan',
        ],
        'menu.language' => [
            'id' => '🌐 Bahasa',
            'en' => '🌐 Language',
            'ms' => '🌐 Bahasa',
        ],
        'menu.placeholder' => [
            'id' => 'Pilih tombol atau ketik /produk',
            'en' => 'Choose a button or type /products',
            'ms' => 'Pilih butang atau taip /produk',
        ],
        'support.message' => [
            'id' => "💬 Support\nAda kendala order, pembayaran, atau login akun?\n\nKirim detail masalah kamu di sini. Kalau soal pesanan, sertakan invoice biar lebih cepat dicek.",
            'en' => "💬 Support\nNeed help with an order, payment, or account login?\n\nSend the details here. Include the invoice number for order-related help.",
            'ms' => "💬 Sokongan\nAda masalah dengan pesanan, bayaran atau log masuk akaun?\n\nHantar butiran masalah anda di sini. Jika berkaitan pesanan, sertakan nombor invois supaya kami boleh semak dengan lebih cepat.",
        ],
        'support.contact' => [
            'id' => '💬 Hubungi Admin',
            'en' => '💬 Chat with Admin',
            'ms' => '💬 Hubungi Admin',
        ],
        'account.title' => [
            'id' => '👤 Akun Saya',
            'en' => '👤 My Account',
            'ms' => '👤 Akaun Saya',
        ],
        'account.name' => [
            'id' => 'Nama: ',
            'en' => 'Name: ',
            'ms' => 'Nama: ',
        ],
        'account.username' => [
            'id' => 'Username: ',
            'en' => 'Username: ',
            'ms' => 'Nama pengguna: ',
        ],
        'account.total_orders' => [
            'id' => 'Total pesanan: ',
            'en' => 'Total orders: ',
            'ms' => 'Jumlah pesanan: ',
        ],
        'account.latest_order' => [
            'id' => 'Pesanan terakhir: ',
            'en' => 'Latest order: ',
            'ms' => 'Pesanan terakhir: ',
        ],
        'account.follow_up' => [
            'id' => 'Mau cek pesanan kamu?',
            'en' => 'Want to check your orders?',
            'ms' => 'Mahu semak pesanan anda?',
        ],
        'account.orders_button' => [
            'id' => '📦 Lihat Pesanan',
            'en' => '📦 View Orders',
            'ms' => '📦 Semak Pesanan',
        ],
        'status.payment.pending' => [
            'id' => 'Menunggu pembayaran',
            'en' => 'Awaiting payment',
            'ms' => 'Menunggu bayaran',
        ],
        'status.payment.paid' => [
            'id' => 'Dibayar',
            'en' => 'Paid',
            'ms' => 'Dibayar',
        ],
        'status.payment.failed' => [
            'id' => 'Gagal',
            'en' => 'Failed',
            'ms' => 'Gagal',
        ],
        'status.payment.expired' => [
            'id' => 'Kedaluwarsa',
            'en' => 'Expired',
            'ms' => 'Tamat tempoh',
        ],
        'status.payment.refunded' => [
            'id' => 'Dikembalikan',
            'en' => 'Refunded',
            'ms' => 'Dipulangkan',
        ],
        'community_gate.join_button' => [
            'id' => '📢 Gabung kanal',
            'en' => '📢 Join Channel',
            'ms' => '📢 Sertai Saluran',
        ],
        'community_gate.verify_button' => [
            'id' => '✅ Saya sudah bergabung',
            'en' => '✅ I Have Joined',
            'ms' => '✅ Saya Sudah Sertai',
        ],
        'community_gate.not_joined' => [
            'id' => 'Kamu belum terdeteksi join channel.',
            'en' => 'You have not joined the channel yet.',
            'ms' => 'Anda belum dikesan menyertai saluran.',
        ],
        'community_gate.message' => [
            'id' => "🔒 Gabung kanal dulu\n━━━━━━━━━━━━━━\nAgar bisa lanjut belanja dan dapat info stok terbaru, kamu perlu bergabung dengan kanal Store Channel dulu.\n\nKalau sudah bergabung tapi status kamu belum bisa dicek, tekan tombol “Saya sudah bergabung” untuk coba lagi. Jika Telegram sedang gangguan, coba lagi nanti.",
            'en' => "🔒 Join the channel first\n━━━━━━━━━━━━━━\nBefore you can continue shopping, please join the Store Channel. Then press “I Have Joined” to verify.",
            'ms' => "🔒 Sertai saluran dahulu\n━━━━━━━━━━━━━━\nUntuk terus membeli-belah dan menerima maklumat stok terkini, sila sertai Store Channel dahulu.\n\nJika sudah sertai tetapi status anda belum dapat disemak, tekan butang “Saya Sudah Sertai” untuk cuba lagi. Jika Telegram sedang mengalami gangguan, cuba lagi nanti.",
        ],
        'privacy.message' => [
            'id' => "🔒 Lanjut di chat pribadi\nDemi keamanan, detail order, pembayaran, dan data akun tidak dikirim ke group.\n\nBuka chat pribadi dengan bot untuk lanjut.",
            'en' => "🔒 Continue in a private chat\nFor security, order, payment, and account details are not sent to groups.\n\nOpen a private chat with the bot to continue.",
            'ms' => "🔒 Teruskan di chat peribadi\nDemi keselamatan, butiran pesanan, bayaran dan data akaun tidak dihantar ke kumpulan.\n\nBuka chat peribadi dengan bot untuk teruskan.",
        ],
        'privacy.callback' => [
            'id' => 'Demi keamanan, lanjutkan di chat pribadi dengan bot.',
            'en' => 'For security, continue in a private chat with the bot.',
            'ms' => 'Demi keselamatan, teruskan di chat peribadi dengan bot.',
        ],
        'callback.community_gate_required' => [
            'id' => 'Gabung Store Channel dulu supaya bisa lanjut belanja.',
            'en' => 'Join the Store Channel first to continue shopping.',
            'ms' => 'Sertai Store Channel dahulu untuk terus membeli-belah.',
        ],
        'callback.unknown_action' => [
            'id' => 'Aksi ini belum dikenali. Coba pilih menu lain.',
            'en' => 'This action is not recognized. Please choose another option.',
            'ms' => 'Tindakan ini tidak dikenali. Sila pilih menu lain.',
        ],
    ];

    public function get(string $key, string $locale): string
    {
        $translations = self::TRANSLATIONS[$key] ?? self::TRANSLATIONS['translation.missing'];

        return $translations[$locale] ?? $translations['en'];
    }
}
