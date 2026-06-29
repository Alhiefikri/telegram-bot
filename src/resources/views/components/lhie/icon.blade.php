@props(['name' => 'default', 'class' => 'h-5 w-5'])

@php
    $icons = [
        'orders' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4.5 7.5h15L18.75 20.25H5.25L4.5 7.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 7.5a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'pending' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12a8 8 0 1 1-3-6.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="m17.5 3.8 1.7 2.1 2.2-1.3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'processing' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7 7h3V4l-4 4 4 4V9h6a3 3 0 1 1 0 6h-1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M17 17h-3v3l4-4-4-4v3H8a3 3 0 1 1 0-6h1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'products' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 3 4.5 7.5 12 12l7.5-4.5L12 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4.5 12 12 16.5 19.5 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 16.5 12 21l7.5-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'revenue' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 4v16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.5 8.25c0-1.66-2.01-3-4.5-3s-4.5 1.34-4.5 3 2.01 3 4.5 3 4.5 1.34 4.5 3-2.01 3-4.5 3-4.5-1.34-4.5-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'payment' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="3.5" y="5" width="17" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 9h17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M7 14h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10.3 4.6c.9-1.2 2.5-1.2 3.4 0l.3.4c.4.5 1.1.8 1.8.7l.5-.1c1.5-.2 2.8.8 3 2.3l.1.5c.1.7.4 1.4.9 1.8l.4.3c1.2.9 1.2 2.5 0 3.4l-.4.3c-.5.4-.8 1.1-.9 1.8l-.1.5c-.2 1.5-1.5 2.5-3 2.3l-.5-.1c-.7-.1-1.4.2-1.8.7l-.3.4c-.9 1.2-2.5 1.2-3.4 0l-.3-.4c-.4-.5-1.1-.8-1.8-.7l-.5.1c-1.5.2-2.8-.8-3-2.3l-.1-.5c-.1-.7-.4-1.4-.9-1.8l-.4-.3c-1.2-.9-1.2-2.5 0-3.4l.4-.3c.5-.4.8-1.1.9-1.8l.1-.5c.2-1.5 1.5-2.5 3-2.3l.5.1c.7.1 1.4-.2 1.8-.7l.3-.4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 9.25A2.75 2.75 0 1 1 9.25 12 2.75 2.75 0 0 1 12 9.25Z" stroke="currentColor" stroke-width="1.6"/></svg>',
        'support' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M5.5 12a6.5 6.5 0 1 1 13 0v4.5a2 2 0 0 1-2 2H15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 20h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M5.5 14h1a1 1 0 0 0 1-1v-1a1 1 0 0 0-1-1h-1v3Zm13 0h-1a1 1 0 0 1-1-1v-1a1 1 0 0 1 1-1h1v3Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'stock' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 3 4 7.5 12 12l8-4.5L12 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4 16.5 12 21l8-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 12l8 4.5L20 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'default' => '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 4v16M4 12h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    ];
@endphp

@php
    $svg = $icons[$name] ?? $icons['default'];

    $svg = str_replace('<svg ', '<svg class="'.e($class).'" ', $svg);
@endphp

{!! $svg !!}
