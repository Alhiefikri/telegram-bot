<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Static Telegram copy icon tokens. Product icons remain Product-owned and
     * menu button icons remain in their existing independent capture slots.
     *
     * @var list<array{key: string, label: string, emoji: string}>
     */
    private const ICONS = [
        ['key' => 'message.profile_id', 'label' => 'ID Telegram', 'emoji' => '🆔'],
        ['key' => 'message.link', 'label' => 'Tautan', 'emoji' => '🔗'],
        ['key' => 'message.payment_card', 'label' => 'Pembayaran', 'emoji' => '💳'],
        ['key' => 'message.users', 'label' => 'Pengguna', 'emoji' => '👥'],
        ['key' => 'message.activity', 'label' => 'Aktivitas', 'emoji' => '📊'],
        ['key' => 'message.delivery', 'label' => 'Pengiriman cepat', 'emoji' => '⚡'],
        ['key' => 'message.maintenance', 'label' => 'Maintenance', 'emoji' => '⏸️'],
        ['key' => 'message.add', 'label' => 'Tambah', 'emoji' => '➕'],
        ['key' => 'message.activity_list', 'label' => 'Riwayat', 'emoji' => '📋'],
        ['key' => 'message.success', 'label' => 'Berhasil', 'emoji' => '✅'],
        ['key' => 'message.cancel', 'label' => 'Batal', 'emoji' => '✖️'],
        ['key' => 'message.notification', 'label' => 'Notifikasi', 'emoji' => '🔔'],
        ['key' => 'message.error', 'label' => 'Gagal', 'emoji' => '❌'],
        ['key' => 'message.privacy', 'label' => 'Privasi', 'emoji' => '🔒'],
        ['key' => 'message.category', 'label' => 'Kategori', 'emoji' => '📂'],
        ['key' => 'message.refresh', 'label' => 'Perbarui', 'emoji' => '🔄'],
        ['key' => 'message.cart', 'label' => 'Keranjang', 'emoji' => '🛒'],
        ['key' => 'message.details', 'label' => 'Detail', 'emoji' => '📝'],
        ['key' => 'message.warning', 'label' => 'Peringatan', 'emoji' => '⚠️'],
        ['key' => 'message.world', 'label' => 'Lintas wilayah', 'emoji' => '🌍'],
        ['key' => 'message.phone', 'label' => 'Aplikasi', 'emoji' => '📱'],
        ['key' => 'message.design', 'label' => 'Ikon', 'emoji' => '🎨'],
        ['key' => 'message.empty', 'label' => 'Kosong', 'emoji' => '⬜'],
        ['key' => 'message.delete', 'label' => 'Hapus', 'emoji' => '🗑️'],
        ['key' => 'message.compass', 'label' => 'Navigasi', 'emoji' => '🧭'],
        ['key' => 'message.stock_available', 'label' => 'Stok tersedia', 'emoji' => '🟢'],
        ['key' => 'message.stock_empty', 'label' => 'Stok habis', 'emoji' => '🔴'],
        ['key' => 'message.crypto', 'label' => 'Crypto', 'emoji' => '💎'],
        ['key' => 'message.price_tag', 'label' => 'Tag harga', 'emoji' => '🏷️'],
        ['key' => 'message.price_tag_text', 'label' => 'Tag harga teks', 'emoji' => '🏷'],
        ['key' => 'message.celebration', 'label' => 'Perayaan / hemat', 'emoji' => '🎉'],
        ['key' => 'message.fire', 'label' => 'Stok rendah', 'emoji' => '🔥'],
        ['key' => 'message.edit', 'label' => 'Edit', 'emoji' => '✏️'],
        ['key' => 'message.quantity', 'label' => 'Kuantitas', 'emoji' => '🔢'],
        ['key' => 'message.cash', 'label' => 'Nominal', 'emoji' => '💵'],
        ['key' => 'message.fee', 'label' => 'Biaya', 'emoji' => '💸'],
        ['key' => 'message.deadline', 'label' => 'Batas waktu', 'emoji' => '⏳'],
        ['key' => 'message.scan', 'label' => 'Scan', 'emoji' => '📲'],
        ['key' => 'message.info', 'label' => 'Info', 'emoji' => 'ℹ️'],
        ['key' => 'message.ticket', 'label' => 'Tiket / pesanan', 'emoji' => '🎫'],
        ['key' => 'message.receive', 'label' => 'Penerima', 'emoji' => '📥'],
        ['key' => 'message.photo', 'label' => 'Bukti foto', 'emoji' => '📸'],
        ['key' => 'message.search', 'label' => 'Pencarian', 'emoji' => '🔎'],
        ['key' => 'message.pin', 'label' => 'Status', 'emoji' => '📌'],
        ['key' => 'message.expired', 'label' => 'Kedaluwarsa', 'emoji' => '⌛'],
        ['key' => 'message.trophy', 'label' => 'Pesanan sukses', 'emoji' => '🏆'],
        ['key' => 'message.sparkle', 'label' => 'Ucapan', 'emoji' => '✨'],
        ['key' => 'message.broadcast', 'label' => 'Broadcast', 'emoji' => '📣'],
        ['key' => 'message.retry', 'label' => 'Coba lagi', 'emoji' => '🔁'],
        ['key' => 'message.tools', 'label' => 'Alat', 'emoji' => '🛠️'],
        ['key' => 'message.sunrise', 'label' => 'Pagi', 'emoji' => '🌅'],
        ['key' => 'message.sun', 'label' => 'Siang', 'emoji' => '☀️'],
        ['key' => 'message.sunset', 'label' => 'Sore', 'emoji' => '🌇'],
        ['key' => 'message.moon', 'label' => 'Malam', 'emoji' => '🌙'],
        ['key' => 'message.calendar_week', 'label' => 'Minggu', 'emoji' => '🗓'],
        ['key' => 'message.calendar_date', 'label' => 'Tanggal', 'emoji' => '📅'],
        ['key' => 'message.neutral', 'label' => 'Stabil', 'emoji' => '➖'],
        ['key' => 'message.trend_up', 'label' => 'Naik', 'emoji' => '📈'],
        ['key' => 'message.trend_down', 'label' => 'Turun', 'emoji' => '📉'],
        ['key' => 'message.guide', 'label' => 'Panduan', 'emoji' => '📘'],
        ['key' => 'message.view', 'label' => 'Lihat', 'emoji' => '👀'],
        ['key' => 'message.new', 'label' => 'Baru', 'emoji' => '🆕'],
        ['key' => 'message.support', 'label' => 'Bantuan', 'emoji' => '🛟'],
        ['key' => 'message.gift', 'label' => 'Hadiah', 'emoji' => '🎁'],
        ['key' => 'message.attachment', 'label' => 'Lampiran', 'emoji' => '📎'],
        ['key' => 'message.download', 'label' => 'Unduh', 'emoji' => '⬇️'],
        ['key' => 'message.credentials', 'label' => 'Kredensial', 'emoji' => '🔐'],
        ['key' => 'message.tip', 'label' => 'Petunjuk', 'emoji' => '💡'],
        ['key' => 'message.hands', 'label' => 'Dukungan', 'emoji' => '🙌'],
        ['key' => 'message.thanks', 'label' => 'Terima kasih', 'emoji' => '🙏'],
        ['key' => 'message.rocket', 'label' => 'Cepat', 'emoji' => '🚀'],
        ['key' => 'message.urgent', 'label' => 'Urgent', 'emoji' => '🚨'],
        ['key' => 'message.play', 'label' => 'Mulai', 'emoji' => '▶'],
        ['key' => 'message.next', 'label' => 'Lanjut', 'emoji' => '➡️'],
    ];

    public function up(): void
    {
        Schema::table('telegram_ui_icons', function (Blueprint $table): void {
            $table->string('source_emoji', 32)->nullable()->after('fallback_emoji');
            $table->index('source_emoji');
        });

        $now = now();
        $rows = [];
        foreach (self::ICONS as $order => $icon) {
            $rows[] = [
                'key' => $icon['key'],
                'label' => $icon['label'],
                'fallback_emoji' => $icon['emoji'],
                'source_emoji' => $icon['emoji'],
                'custom_emoji_id' => null,
                'sort_order' => 100 + $order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('telegram_ui_icons')->insert($rows);
    }

    public function down(): void
    {
        DB::table('telegram_ui_icons')->where('key', 'like', 'message.%')->delete();

        Schema::table('telegram_ui_icons', function (Blueprint $table): void {
            $table->dropIndex(['source_emoji']);
            $table->dropColumn('source_emoji');
        });
    }
};
