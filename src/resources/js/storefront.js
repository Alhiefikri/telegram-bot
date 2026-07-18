const ACTIVE_CATEGORY_CLASSES = ['neo-filter-active'];
const INACTIVE_CATEGORY_CLASSES = ['neo-filter-inactive'];
const MODAL_FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

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

    const isOpen = () => mobileMenu.getAttribute('aria-hidden') === 'false';
    let opener = null;
    const closeMenu = (restoreFocus = true) => {
        if (!isOpen()) {
            return;
        }

        toggleMobileMenu(mobileMenu, menuIconTop, menuIconMiddle, menuIconBottom, menuButton, backdrop, true);
        if (restoreFocus) {
            (opener || menuButton).focus();
        }
        opener = null;
    };

    menuButton.addEventListener('click', () => {
        if (isOpen()) {
            closeMenu();
            return;
        }

        opener = document.activeElement;
        toggleMobileMenu(mobileMenu, menuIconTop, menuIconMiddle, menuIconBottom, menuButton, backdrop);
        window.requestAnimationFrame(() => (closeButton || mobileMenu.querySelector(MODAL_FOCUSABLE_SELECTOR))?.focus());
    });

    closeButton?.addEventListener('click', () => closeMenu());
    backdrop?.addEventListener('click', () => closeMenu());

    mobileMenu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => closeMenu(false));
    });

    document.addEventListener('keydown', (event) => {
        if (!isOpen()) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = Array.from(mobileMenu.querySelectorAll(MODAL_FOCUSABLE_SELECTOR))
            .filter((element) => element.offsetParent !== null);
        const first = focusable[0];
        const last = focusable.at(-1);

        if (!first || !last) {
            event.preventDefault();
            closeButton?.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
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
    const status = button.closest('[data-copy-group]')?.querySelector('[data-copy-status]');
    const statusText = button.getAttribute('data-copy-success') || 'Tersalin.';

    if (status) {
        status.textContent = statusText;
    }

    button.textContent = 'Tersalin';
    window.setTimeout(() => {
        button.textContent = originalText;
        if (status) {
            status.textContent = '';
        }
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
    const secondsLabel = (seconds) => `${Math.max(1, Number.parseInt(seconds, 10) || 60)} detik`;
    const isTerminalStatus = (data) => {
        const paymentStatus = String(data?.payment_status || '').toLowerCase();
        const orderStatus = String(data?.order_status || '').toLowerCase();

        return data?.is_terminal === true || terminalStatuses.has(paymentStatus) || terminalStatuses.has(orderStatus);
    };

    containers.forEach((container) => {
        const statusUrl = container.getAttribute('data-status-url');
        const redirectUrl = container.getAttribute('data-redirect-url');
        const title = container.querySelector('[data-payment-status-title]');
        const message = container.querySelector('[data-payment-status-message]');
        const announcement = container.querySelector('[data-payment-status-announcement]');
        const followup = container.querySelector('[data-payment-status-followup]');
        const button = container.querySelector('[data-payment-status-check]');
        let lastAnnouncement = '';
        let waiting = false;

        if (!statusUrl || !button || container.getAttribute('data-terminal') === 'true') {
            return;
        }

        const setStatus = (titleText, messageText) => {
            if (title) {
                title.textContent = titleText;
            }

            if (message) {
                message.textContent = messageText;
            }

            const nextAnnouncement = `${titleText}. ${messageText}`;
            if (announcement && nextAnnouncement !== lastAnnouncement) {
                announcement.textContent = nextAnnouncement;
                lastAnnouncement = nextAnnouncement;
            }
        };

        const cooldown = (seconds) => {
            waiting = true;
            button.disabled = true;
            window.setTimeout(() => {
                waiting = false;
                if (container.getAttribute('data-terminal') !== 'true') {
                    button.disabled = false;
                }
            }, (Math.max(1, Number.parseInt(seconds, 10) || 60)) * 1000);
        };

        button.addEventListener('click', async () => {
            if (waiting) {
                return;
            }

            button.disabled = true;
            setStatus('Mengecek pembayaran', 'Tunggu sebentar, status QRIS sedang dicek.');

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

                if (response.status === 429) {
                    const retryAfter = data?.retry_after || response.headers.get('Retry-After') || 60;
                    setStatus('Terlalu sering cek status', data?.message || `Coba lagi dalam ${secondsLabel(retryAfter)}.`);
                    cooldown(retryAfter);
                    return;
                }

                if (!data || typeof data !== 'object') {
                    setStatus('Status belum bisa dibaca', 'Coba cek lagi beberapa saat lagi.');
                    button.disabled = false;
                    return;
                }

                if (data.is_paid === true) {
                    setStatus('Pembayaran terverifikasi', data.message || 'Invoice akan dibuka ulang untuk menampilkan data akun.');
                    container.setAttribute('data-terminal', 'true');
                    window.location.href = data.redirect_url || redirectUrl || window.location.href;
                    return;
                }

                if (isTerminalStatus(data)) {
                    setStatus('Status pembayaran final', data.message || 'QRIS ini tidak bisa dibayar lagi. Buat order baru dari katalog.');
                    container.setAttribute('data-terminal', 'true');
                    button.hidden = true;
                    followup?.classList.remove('hidden');
                    return;
                }

                if (data.ok === false) {
                    setStatus('Status belum bisa dicek', data.message || 'Coba lagi beberapa saat lagi.');
                    button.disabled = false;
                    return;
                }

                setStatus('Pembayaran belum masuk', data.message || 'Jika sudah bayar, coba cek lagi beberapa saat lagi.');
                button.disabled = false;
            } catch {
                setStatus('Koneksi gagal', 'Cek internet lalu coba lagi.');
                button.disabled = false;
            }
        });
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
        quantityAnnouncement: '',
        modalOpener: null,
        restoreCheckout: null,
        init() {
            const restore = this.$root?.dataset.checkoutRestore;

            if (!restore) {
                return;
            }

            try {
                this.restoreCheckout = JSON.parse(restore);
            } catch {
                return;
            }

            this.$nextTick(() => this.reopenFailedCheckout());
        },
        openProductModal(product, options = {}) {
            if (!product?.availability?.canOpenModal) {
                return;
            }

            this.modalOpener = options.opener || document.activeElement;
            this.selectedProduct = product;
            this.quantity = Number.parseInt(options.quantity, 10) || 1;
            this.quantityAnnouncement = '';
            this.notes = '';
            this.buyerHandle = options.buyerHandle || '';
            this.paymentMethod = options.paymentMethod || (this.isEnglishBrowser() && product?.checkout?.websiteBinancePay ? 'binance_pay' : 'qris');
            this.productModalOpen = true;
            document.body.style.overflow = 'hidden';
            this.$nextTick(() => this.focusModal(options.focusInvalid));
        },
        closeProductModal() {
            const opener = this.modalOpener;

            this.productModalOpen = false;
            window.setTimeout(() => {
                this.selectedProduct = null;
                if (opener && typeof opener.focus === 'function') {
                    opener.focus();
                }
            }, 300);
            document.body.style.overflow = '';
        },
        decreaseQuantity() {
            this.setQuantity(Math.max(1, this.quantity - 1));
        },
        increaseQuantity() {
            if (!this.selectedProduct) {
                return;
            }

            this.setQuantity(Math.min(this.availableStock(), this.quantity + 1));
        },
        setQuantity(quantity) {
            if (quantity === this.quantity) {
                return;
            }

            this.quantity = quantity;
            this.quantityAnnouncement = `Jumlah ${quantity}. Total produk ${this.formatIdr(this.selectedTotal())}.`;
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
        paymentMethodForChoice(choice) {
            return ['websiteBinancePay', 'website_binance_pay'].includes(choice?.key) ? 'binance_pay' : 'qris';
        },
        focusModal(focusInvalid = false) {
            const panel = this.$refs.dialogPanel;
            const focusTarget = () => {
                const target = focusInvalid
                    ? panel?.querySelector('[aria-invalid="true"], #storefront-buyer-handle')
                    : panel?.querySelector('#storefront-buyer-handle');

                (target || panel)?.focus?.();
            };

            focusTarget();
            window.requestAnimationFrame(focusTarget);
            window.setTimeout(focusTarget, 50);
            window.setTimeout(focusTarget, 300);
        },
        handleModalKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeProductModal();
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusable = Array.from(this.$refs.dialogPanel?.querySelectorAll(MODAL_FOCUSABLE_SELECTOR) || [])
                .filter((element) => element.offsetParent !== null);

            if (!focusable.length) {
                event.preventDefault();
                this.$refs.dialogPanel?.focus();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
        reopenFailedCheckout() {
            if (!this.restoreCheckout?.productId) {
                return;
            }

            const card = document.getElementById(`product-${this.restoreCheckout.productId}`);
            const payload = card?.dataset?.product;

            if (!payload) {
                return;
            }

            try {
                this.openProductModal(JSON.parse(payload), {
                    opener: card,
                    quantity: this.restoreCheckout.quantity,
                    buyerHandle: this.restoreCheckout.buyerHandle,
                    paymentMethod: this.restoreCheckout.paymentMethod,
                    focusInvalid: true,
                });
                window.setTimeout(() => this.focusModal(true), 800);
            } catch {
                // Keep the global checkout error visible if the product payload is stale.
            }
        },
    };
}

export function registerStorefrontAlpine(Alpine) {
    Alpine.data('storefrontModal', storefrontModal);
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
