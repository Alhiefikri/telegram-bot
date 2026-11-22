<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUGS = [
        'adobe',
        'apple-music',
        'canva',
        'capcut',
        'chatgpt',
        'china-models-api',
        'claude-api-key',
        'duolingo',
        'gemini-2',
        'gmail',
        'gpt-api',
        'gpt-claude-api',
        'lovable',
        'microsoft',
        'muse-ai',
        'prime-video',
        'spotify',
    ];

    private const DIRECTORY = '/storefront/family-icons/';

    public function up(): void
    {
        foreach (self::SLUGS as $slug) {
            DB::table('product_families')
                ->where('slug', $slug)
                ->whereNull('sidebar_icon_path')
                ->update(['sidebar_icon_path' => self::DIRECTORY.$slug.'.webp']);
        }
    }

    public function down(): void
    {
        DB::table('product_families')
            ->whereIn('sidebar_icon_path', array_map(
                static fn (string $slug): string => self::DIRECTORY.$slug.'.webp',
                self::SLUGS,
            ))
            ->update(['sidebar_icon_path' => null]);
    }
};
