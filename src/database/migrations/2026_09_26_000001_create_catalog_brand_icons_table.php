<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brand list is data, not configuration: admins change brand icons from
     * the Admin Dashboard, and the Store Bot reads them without a rebuild.
     *
     * @var list<array{slug: string, label: string, patterns: list<string>, fallback: string}>
     */
    private const BRANDS = [
        ['slug' => 'canva', 'label' => 'Canva', 'patterns' => ['canva'], 'fallback' => '🎨'],
        ['slug' => 'chatgpt', 'label' => 'ChatGPT', 'patterns' => ['chatgpt', 'chat gpt', 'openai', 'gpt plus', 'gpt go'], 'fallback' => '🤖'],
        ['slug' => 'gemini', 'label' => 'Gemini', 'patterns' => ['gemini', 'google ai', 'google ai pro', 'veo'], 'fallback' => '✨'],
        ['slug' => 'grok', 'label' => 'Grok', 'patterns' => ['grok', 'xai', 'x.ai'], 'fallback' => '✖️'],
        ['slug' => 'genspark', 'label' => 'Genspark', 'patterns' => ['genspark', 'gen spark'], 'fallback' => '✨'],
        ['slug' => 'capcut', 'label' => 'CapCut', 'patterns' => ['capcut'], 'fallback' => '🎬'],
        ['slug' => 'adobe', 'label' => 'Adobe', 'patterns' => ['adobe', 'creative cloud', 'photoshop', 'illustrator'], 'fallback' => '🎨'],
        ['slug' => 'cursor', 'label' => 'Cursor', 'patterns' => ['cursor'], 'fallback' => '💻'],
        ['slug' => 'youtube', 'label' => 'YouTube', 'patterns' => ['youtube', 'you tube', 'yt premium'], 'fallback' => '▶️'],
        ['slug' => 'kling', 'label' => 'Kling', 'patterns' => ['kling'], 'fallback' => '🎞️'],
        ['slug' => 'lovable', 'label' => 'Lovable', 'patterns' => ['lovable'], 'fallback' => '💗'],
        ['slug' => 'notion', 'label' => 'Notion', 'patterns' => ['notion'], 'fallback' => '📝'],
        ['slug' => 'netflix', 'label' => 'Netflix', 'patterns' => ['netflix'], 'fallback' => '📺'],
        ['slug' => 'surfshark', 'label' => 'Surfshark', 'patterns' => ['surfshark'], 'fallback' => '🦈'],
    ];

    public function up(): void
    {
        Schema::create('catalog_brand_icons', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->json('patterns');
            $table->string('fallback_emoji', 32);
            $table->string('custom_emoji_id')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        foreach (self::BRANDS as $order => $brand) {
            $rows[] = [
                'slug' => $brand['slug'],
                'label' => $brand['label'],
                'patterns' => json_encode($brand['patterns'], JSON_THROW_ON_ERROR),
                'fallback_emoji' => $brand['fallback'],
                'custom_emoji_id' => null,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('catalog_brand_icons')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_brand_icons');
    }
};
