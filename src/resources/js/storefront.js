const ACTIVE_CATEGORY_CLASSES = ['bg-slate-900', 'text-white', 'border-slate-900'];
const INACTIVE_CATEGORY_CLASSES = ['bg-white', 'text-slate-600', 'border-slate-200'];

function toggleMobileMenu(menu, iconTop, iconBottom, forceClosed = null) {
    const shouldOpen = forceClosed === null ? menu.classList.contains('hidden') : !forceClosed;

    if (shouldOpen) {
        menu.classList.remove('hidden');
        window.setTimeout(() => {
            menu.classList.remove('opacity-0', 'scale-95');
            menu.classList.add('opacity-100', 'scale-100');
        }, 10);
        iconTop?.classList.add('rotate-45', 'translate-y-1');
        iconBottom?.classList.add('-rotate-45', '-translate-y-[3px]');
        return;
    }

    menu.classList.add('opacity-0', 'scale-95');
    menu.classList.remove('opacity-100', 'scale-100');
    window.setTimeout(() => {
        menu.classList.add('hidden');
    }, 200);
    iconTop?.classList.remove('rotate-45', 'translate-y-1');
    iconBottom?.classList.remove('-rotate-45', '-translate-y-[3px]');
}

function initMobileMenu() {
    const menuButton = document.getElementById('mobile-menu-btn');
    const menuIconTop = document.getElementById('menu-icon-1');
    const menuIconBottom = document.getElementById('menu-icon-2');
    const mobileMenu = document.getElementById('mobile-menu');

    if (!menuButton || !mobileMenu) {
        return;
    }

    menuButton.addEventListener('click', () => {
        toggleMobileMenu(mobileMenu, menuIconTop, menuIconBottom);
    });

    mobileMenu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            toggleMobileMenu(mobileMenu, menuIconTop, menuIconBottom, true);
        });
    });
}

function initRevealItems() {
    const revealItems = document.querySelectorAll('.reveal-item');

    if (!revealItems.length) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        revealItems.forEach((item) => item.classList.add('revealed'));
        return;
    }

    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (!entry.isIntersecting) {
                return;
            }

            window.setTimeout(() => {
                entry.target.classList.add('revealed');
            }, index * 60);
            revealObserver.unobserve(entry.target);
        });
    }, {
        threshold: 0.05,
        rootMargin: '0px 0px -40px 0px',
    });

    revealItems.forEach((item) => revealObserver.observe(item));
}

function initCategoryFilters() {
    const categoryButtons = document.querySelectorAll('.category-btn');
    const productCards = document.querySelectorAll('.product-card');

    if (!categoryButtons.length || !productCards.length) {
        return;
    }

    categoryButtons.forEach((button) => {
        button.addEventListener('click', () => {
            categoryButtons.forEach((otherButton) => {
                otherButton.classList.remove(...ACTIVE_CATEGORY_CLASSES);
                otherButton.classList.add(...INACTIVE_CATEGORY_CLASSES);
                otherButton.setAttribute('aria-pressed', 'false');
            });

            button.classList.remove(...INACTIVE_CATEGORY_CLASSES);
            button.classList.add(...ACTIVE_CATEGORY_CLASSES);
            button.setAttribute('aria-pressed', 'true');

            const filterValue = button.getAttribute('data-filter');

            productCards.forEach((card, index) => {
                const cardCategory = card.getAttribute('data-category');
                const shouldShow = filterValue === 'all' || cardCategory === filterValue;

                if (shouldShow) {
                    card.hidden = false;
                    window.setTimeout(() => {
                        card.classList.remove('opacity-0');
                    }, 50 + index * 40);
                    return;
                }

                card.classList.add('opacity-0');
                window.setTimeout(() => {
                    card.hidden = true;
                }, 300);
            });
        });
    });
}

function renderCounter(target) {
    const limit = Number.parseInt(target.getAttribute('data-counter'), 10);
    const suffix = target.getAttribute('data-suffix') || '';

    if (!Number.isFinite(limit)) {
        return;
    }

    let count = 0;
    const duration = 1800;
    const steps = 40;
    const increment = Math.ceil(limit / steps);
    const stepTime = duration / steps;

    const timer = window.setInterval(() => {
        count += increment;
        if (count >= limit) {
            target.textContent = limit.toLocaleString('id-ID') + suffix;
            window.clearInterval(timer);
            return;
        }

        target.textContent = count.toLocaleString('id-ID') + suffix;
    }, stepTime);
}

function initCounters() {
    const counters = document.querySelectorAll('.counter-number');

    if (!counters.length) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        counters.forEach(renderCounter);
        return;
    }

    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            renderCounter(entry.target);
            counterObserver.unobserve(entry.target);
        });
    }, { threshold: 0.1 });

    counters.forEach((counter) => counterObserver.observe(counter));
}

function initFaqAccordion() {
    document.querySelectorAll('.faq-toggle').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const item = toggle.parentElement;
            const content = item?.querySelector('.faq-content');
            const icon = toggle.querySelector('svg');
            const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

            if (!item || !content) {
                return;
            }

            document.querySelectorAll('.faq-item').forEach((otherItem) => {
                if (otherItem === item) {
                    return;
                }

                otherItem.querySelector('.faq-toggle')?.setAttribute('aria-expanded', 'false');
                const otherContent = otherItem.querySelector('.faq-content');
                if (otherContent) {
                    otherContent.classList.add('max-h-0');
                    otherContent.classList.remove('max-h-96');
                }
                otherItem.querySelector('.faq-toggle svg')?.classList.remove('rotate-180');
            });

            if (isExpanded) {
                toggle.setAttribute('aria-expanded', 'false');
                content.classList.add('max-h-0');
                content.classList.remove('max-h-96');
                icon?.classList.remove('rotate-180');
                return;
            }

            toggle.setAttribute('aria-expanded', 'true');
            content.classList.remove('max-h-0');
            content.classList.add('max-h-96');
            icon?.classList.add('rotate-180');
        });
    });
}

export default function initStorefront() {
    const boot = () => {
        document.body.classList.add('js-enabled');

        initMobileMenu();
        initRevealItems();
        initCategoryFilters();
        initCounters();
        initFaqAccordion();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
        return;
    }

    boot();
}
