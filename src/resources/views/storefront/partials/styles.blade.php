<style>
    body {
        font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        background-color: #fcfcfc;
        color: #09090b;
    }

    h1, h2, h3, h4, .font-display {
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .bg-grid-pattern {
        background-color: rgba(15, 23, 42, 0.03);
    }

    .hover-lift {
        transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease;
    }
    .hover-lift:hover {
        transform: translateY(-2px);
    }

    .reveal-item {
        opacity: 0;
        transform: translateY(16px);
        transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .reveal-item.revealed {
        opacity: 1;
        transform: translateY(0);
    }

    @keyframes marquee {
        0% { transform: translateX(0%); }
        100% { transform: translateX(-50%); }
    }
    .animate-marquee {
        display: flex;
        width: max-content;
        animation: marquee 42s linear infinite;
    }

    .hero-preview-panel {
        animation: hero-panel-in 700ms cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    .hero-flow-row {
        animation: hero-flow 5.5s cubic-bezier(0.22, 1, 0.36, 1) infinite;
    }

    .hero-flow-row:nth-child(2) {
        animation-delay: 800ms;
    }

    .hero-flow-row:nth-child(3) {
        animation-delay: 1600ms;
    }

    @keyframes hero-panel-in {
        from { opacity: 0; transform: translate3d(0, 20px, 0) scale(0.97); }
        to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    @keyframes hero-flow {
        0%, 100% { opacity: 0.55; transform: translate3d(0, 0, 0); }
        42% { opacity: 1; transform: translate3d(0, -2px, 0); }
    }

    .counter-number {
        font-variant-numeric: tabular-nums;
    }

    @media (prefers-reduced-motion: reduce) {
        .animate-marquee {
            animation: none !important;
            transform: none !important;
            width: auto !important;
            flex-wrap: wrap !important;
            justify-content: center !important;
            gap: 2rem !important;
        }
        .reveal-item {
            opacity: 1 !important;
            transform: none !important;
            transition: none !important;
        }
        .hover-lift:hover {
            transform: none !important;
        }
        .hero-preview-panel,
        .hero-flow-row {
            animation: none !important;
            opacity: 1 !important;
            transform: none !important;
        }
    }
</style>
