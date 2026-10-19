<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add-Balance method buttons. Each funding rail owns a distinct editable
     * slot so the QRIS, Binance Pay and USDT BEP20 buttons never alias onto one
     * shared crypto icon. These slots intentionally carry no source_emoji: the
     * glyphs already belong to the message-copy slots, and one emoji stays
     * editable in exactly one list.
     *
     * @var list<array{key: string, label: string, emoji: string}>
     */
    private const SLOTS = [
        ['key' => 'menu.balance.qris', 'label' => 'Tambah Saldo · QRIS', 'emoji' => '🇮🇩'],
        ['key' => 'menu.balance.binance_pay', 'label' => 'Tambah Saldo · Binance Pay', 'emoji' => '💎'],
        ['key' => 'menu.balance.usdt_bep20', 'label' => 'Tambah Saldo · USDT BEP20', 'emoji' => '⛓️'],
    ];

    public function up(): void
    {
        $now = now();
        $rows = [];
        foreach (self::SLOTS as $order => $slot) {
            $rows[] = [
                'key' => $slot['key'],
                'label' => $slot['label'],
                'fallback_emoji' => $slot['emoji'],
                'source_emoji' => null,
                'custom_emoji_id' => null,
                'sort_order' => 300 + $order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('telegram_ui_icons')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('telegram_ui_icons')
            ->whereIn('key', array_column(self::SLOTS, 'key'))
            ->delete();
    }
};
