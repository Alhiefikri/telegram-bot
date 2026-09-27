<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named Store Bot navigation slots. The plain Unicode fallback lives in the
     * locale copy itself; this row only records which navigation key may carry a
     * captured Telegram custom emoji, and gives admins a readable label.
     *
     * @var list<array{key: string, label: string, fallback: string}>
     */
    private const SLOTS = [
        ['key' => 'menu.products', 'label' => 'Lihat Produk', 'fallback' => '🛍️'],
        ['key' => 'menu.balance', 'label' => 'Saldo', 'fallback' => '💰'],
        ['key' => 'menu.orders', 'label' => 'Pesanan', 'fallback' => '📦'],
        ['key' => 'menu.support', 'label' => 'Support', 'fallback' => '💬'],
        ['key' => 'menu.language', 'label' => 'Bahasa', 'fallback' => '🌐'],
        ['key' => 'menu.channel', 'label' => 'Channel', 'fallback' => '📢'],
        ['key' => 'menu.back', 'label' => 'Kembali', 'fallback' => '⬅️'],
        ['key' => 'menu.home', 'label' => 'Beranda', 'fallback' => '🏠'],
    ];

    public function up(): void
    {
        Schema::create('telegram_ui_icons', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('fallback_emoji', 32);
            $table->string('custom_emoji_id')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        foreach (self::SLOTS as $order => $slot) {
            $rows[] = [
                'key' => $slot['key'],
                'label' => $slot['label'],
                'fallback_emoji' => $slot['fallback'],
                'custom_emoji_id' => null,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('telegram_ui_icons')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_ui_icons');
    }
};
