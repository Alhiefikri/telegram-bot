<?php

return [
    'custom_emoji_enabled' => env('TELEGRAM_PRODUCT_CUSTOM_EMOJI_ENABLED', true),
    'default_fallback' => '🛍️',
    'brands' => [
        'chatgpt' => [
            'patterns' => ['chatgpt', 'chat gpt', 'openai', 'gpt plus', 'gpt go'],
            'fallback' => '🤖',
            'custom_emoji_id' => env('TG_ICON_CHATGPT'),
        ],
        'gemini' => [
            'patterns' => ['gemini', 'google ai', 'google ai pro', 'veo'],
            'fallback' => '🤖',
            'custom_emoji_id' => env('TG_ICON_GEMINI'),
        ],
        'grok' => [
            'patterns' => ['grok', 'xai', 'x.ai'],
            'fallback' => '✖️',
            'custom_emoji_id' => env('TG_ICON_GROK'),
        ],
        'capcut' => [
            'patterns' => ['capcut'],
            'fallback' => '🎬',
            'custom_emoji_id' => env('TG_ICON_CAPCUT'),
        ],
        'adobe' => [
            'patterns' => ['adobe', 'creative cloud', 'photoshop', 'illustrator'],
            'fallback' => '🎨',
            'custom_emoji_id' => env('TG_ICON_ADOBE'),
        ],
        'cursor' => [
            'patterns' => ['cursor'],
            'fallback' => '💻',
            'custom_emoji_id' => env('TG_ICON_CURSOR'),
        ],
        'youtube' => [
            'patterns' => ['youtube', 'you tube', 'yt premium'],
            'fallback' => '▶️',
            'custom_emoji_id' => env('TG_ICON_YOUTUBE'),
        ],
        'kling' => [
            'patterns' => ['kling'],
            'fallback' => '🎞️',
            'custom_emoji_id' => env('TG_ICON_KLING'),
        ],
        'lovable' => [
            'patterns' => ['lovable'],
            'fallback' => '💗',
            'custom_emoji_id' => env('TG_ICON_LOVABLE'),
        ],
        'notion' => [
            'patterns' => ['notion'],
            'fallback' => '📝',
            'custom_emoji_id' => env('TG_ICON_NOTION'),
        ],
    ],
];
