const ACTIVE_CATEGORY_CLASSES = ['neo-filter-active'];
const INACTIVE_CATEGORY_CLASSES = ['neo-filter-inactive'];

function toggleMobileMenu(menu, iconTop, iconMiddle, iconBottom, menuButton, backdrop, forceClosed = null) {
    const isClosed = menu.classList.contains('hidden') || menu.getAttribute('aria-hidden') !== 'false';
    const shouldOpen = forceClosed === null ? isClosed : ! forceClosed;

    if (shouldOpen) {
        menu.classList.remove('hidden');
        backdrop?.classList.remove('hidden');
        menu.setAttribute('aria-hidden', 'false');
        menuButton?.setAttribute('aria-expanded', 'true');
        document.body.classList.add('overflow-hidden');

        window.requestAnimationFrame(() => {
            menu.classList.remove('-translate-x-full');
            menu.classList.add('translate-x-0');
            backdrop?.classList.remove('opacity-0');
            backdrop?.classList.add('opacity-100');
        });

        iconTop?.classList.add('rotate-45', 'translate-y-[9px]');
        iconMiddle?.classList.add('opacity-0');
        iconBottom?.classList.add('-rotate-45', '-translate-y-[9px]');
        return;
    }

    menu.classList.add('-translate-x-full');
    menu.classList.remove('translate-x-0');
    backdrop?.classList.add('opacity-0');
    backdrop?.classList.remove('opacity-100');
    menu.setAttribute('aria-hidden', 'true');
    menuButton?.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('overflow-hidden');

    window.setTimeout(() => {
        menu.classList.add('hidden');
        backdrop?.classList.add('hidden');
    }, 250);

    iconTop?.classList.remove('rotate-45', 'translate-y-[9px]');
    iconMiddle?.classList.remove('opacity-0');
    iconBottom?.classList.remove('-rotate-45', '-translate-y-[9px]');
}

function initMobileMenu() {
    const menuButton = document.getElementById('mobile-menu-btn');
    const closeButton = document.getElementById('mobile-menu-close');
    const menuIconTop = document.getElementById('menu-icon-1');
    const menuIconMiddle = document.getElementById('menu-icon-2');
    const menuIconBottom = document.getElementById('menu-icon-3');
    const mobileMenu = document.getElementById('mobile-menu');
    const backdrop = document.getElementById('mobile-menu-backdrop');

    if (! menuButton || ! mobileMenu) {
        return;
    }

    const closeMenu = () => toggleMobileMenu(mobileMenu, menuIconTop, menuIconMiddle, menuIconBottom, menuButton, backdrop, true);

    menuButton.addEventListener('click', () => {
        toggleMobileMenu(mobileMenu, menuIconTop, menuIconMiddle, menuIconBottom, menuButton, backdrop);
    });

    closeButton?.addEventListener('click', closeMenu);
    backdrop?.addEventListener('click', closeMenu);

    mobileMenu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
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
                    otherContent.classList.remove('max-h-[500px]');
                }
                otherItem.querySelector('.faq-toggle svg')?.classList.remove('rotate-180');
            });

            if (isExpanded) {
                toggle.setAttribute('aria-expanded', 'false');
                content.classList.add('max-h-0');
                content.classList.remove('max-h-[500px]');
                icon?.classList.remove('rotate-180');
                return;
            }

            toggle.setAttribute('aria-expanded', 'true');
            content.classList.remove('max-h-0');
            content.classList.add('max-h-[500px]');
            icon?.classList.add('rotate-180');
        });
    });
}

async function writeClipboard(text) {
    if (!text || !navigator.clipboard) {
        return false;
    }

    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

function markCopied(button) {
    const originalText = button.textContent;

    button.textContent = 'Tersalin';
    window.setTimeout(() => {
        button.textContent = originalText;
    }, 1200);
}

function initPaymentActions() {
    document.querySelectorAll('[data-copy-text]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (await writeClipboard(button.getAttribute('data-copy-text'))) {
                markCopied(button);
            }
        });
    });

    document.querySelectorAll('[data-share-text]').forEach((button) => {
        button.addEventListener('click', async () => {
            const text = button.getAttribute('data-share-text') || '';

            if (navigator.share) {
                try {
                    await navigator.share({ text });
                    return;
                } catch {
                    return;
                }
            }

            if (await writeClipboard(text)) {
                markCopied(button);
            }
        });
    });
}

function initShopPaymentStatus() {
    const containers = document.querySelectorAll('[data-shop-payment-status]');

    if (!containers.length) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const terminalStatuses = new Set(['expired', 'cancelled', 'failed']);
    const isTerminalStatus = (data) => {
        const paymentStatus = String(data?.payment_status || '').toLowerCase();
        const orderStatus = String(data?.order_status || '').toLowerCase();

        return terminalStatuses.has(paymentStatus) || terminalStatuses.has(orderStatus);
    };

    containers.forEach((container) => {
        const statusUrl = container.getAttribute('data-status-url');
        const redirectUrl = container.getAttribute('data-redirect-url');
        const pollMs = Number.parseInt(container.getAttribute('data-poll-ms') || '6000', 10) || 6000;
        let timer = null;
        let stopped = false;

        if (!statusUrl || container.getAttribute('data-terminal') === 'true') {
            return;
        }

        const stop = () => {
            stopped = true;

            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        };

        const poll = async () => {
            if (stopped || container.getAttribute('data-terminal') === 'true') {
                stop();
                return;
            }

            try {
                const response = await window.fetch(statusUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                });
                const data = await response.json().catch(() => null);

                if (!data || typeof data !== 'object') {
                    return;
                }

                if (data.is_paid === true) {
                    stop();
                    window.location.href = data.redirect_url || redirectUrl || window.location.href;
                    return;
                }

                if (isTerminalStatus(data)) {
                    container.setAttribute('data-terminal', 'true');
                    stop();
                }
            } catch {
                // Keep the current invoice UI and retry on the next interval.
            }
        };

        timer = window.setInterval(poll, pollMs);
        poll();
    });
}

export function storefrontModal() {
    return {
        productModalOpen: false,
        selectedProduct: null,
        quantity: 1,
        notes: '',
        buyerHandle: '',
        paymentMethod: 'qris',
        openProductModal(product) {
            if (!product?.availability?.canOpenModal) {
                return;
            }

            this.selectedProduct = product;
            this.quantity = 1;
            this.notes = '';
            this.buyerHandle = '';
            this.paymentMethod = this.isEnglishBrowser() && product?.checkout?.websiteBinancePay ? 'binance_pay' : 'qris';
            this.productModalOpen = true;
            document.body.style.overflow = 'hidden';
        },
        closeProductModal() {
            this.productModalOpen = false;
            window.setTimeout(() => {
                this.selectedProduct = null;
            }, 300);
            document.body.style.overflow = '';
        },
        decreaseQuantity() {
            this.quantity = Math.max(1, this.quantity - 1);
        },
        increaseQuantity() {
            if (!this.selectedProduct) {
                return;
            }

            this.quantity = Math.min(this.availableStock(), this.quantity + 1);
        },
        selectedTotal() {
            if (!this.selectedProduct) {
                return 0;
            }

            return (Number.parseInt(this.selectedProduct.price?.amount, 10) || 0) * (Number.parseInt(this.quantity, 10) || 1);
        },
        availableStock() {
            return Math.max(1, Number.parseInt(this.selectedProduct?.stock?.available, 10) || 1);
        },
        formatIdr(amount) {
            return `Rp${Number.parseInt(amount, 10).toLocaleString('id-ID')}`;
        },
        isEnglishBrowser() {
            return window.navigator.language.toLowerCase().startsWith('en');
        },
        orderedCheckoutChoices() {
            if (!this.selectedProduct) {
                return [];
            }

            const choices = [this.selectedProduct.checkout.websiteQris, this.selectedProduct.checkout.websiteBinancePay].filter(Boolean);
            return this.isEnglishBrowser() ? choices.reverse() : choices;
        },
        selectedCheckoutChoice() {
            return this.paymentMethod === 'binance_pay'
                ? this.selectedProduct?.checkout?.websiteBinancePay
                : this.selectedProduct?.checkout?.websiteQris;
        },
    };
}

export function fomoTimer() {
    return {
        timeRemaining: 15 * 60,
        timeDisplay: '15:00',
        init() {
            const savedTime = localStorage.getItem('fomo_timer');
            const savedTimestamp = localStorage.getItem('fomo_timestamp');
            const now = Math.floor(Date.now() / 1000);

            if (savedTime && savedTimestamp) {
                const elapsed = now - Number.parseInt(savedTimestamp, 10);

                this.timeRemaining = Number.parseInt(savedTime, 10) - elapsed;
                if (this.timeRemaining <= 0) {
                    this.timeRemaining = 15 * 60;
                }
            }

            this.updateDisplay();

            window.setInterval(() => {
                this.timeRemaining -= 1;
                if (this.timeRemaining <= 0) {
                    this.timeRemaining = 15 * 60;
                }
                this.updateDisplay();
                localStorage.setItem('fomo_timer', this.timeRemaining);
                localStorage.setItem('fomo_timestamp', Math.floor(Date.now() / 1000));
            }, 1000);
        },
        updateDisplay() {
            const minutes = Math.floor(this.timeRemaining / 60).toString().padStart(2, '0');
            const seconds = (this.timeRemaining % 60).toString().padStart(2, '0');

            this.timeDisplay = `${minutes}:${seconds}`;
        },
    };
}

export function registerStorefrontAlpine(Alpine) {
    Alpine.data('storefrontModal', storefrontModal);
    Alpine.data('fomoTimer', fomoTimer);
}

export default function initStorefront() {
    const boot = () => {
        document.body.classList.add('js-enabled');

        initMobileMenu();
        initRevealItems();
        initCategoryFilters();
        initCounters();
        initFaqAccordion();
        initPaymentActions();
        initShopPaymentStatus();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
        return;
    }

    boot();
}
