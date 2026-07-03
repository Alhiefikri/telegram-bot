<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.body.classList.add('js-enabled');

        const menuBtn = document.getElementById('mobile-menu-btn');
        const menuIcon1 = document.getElementById('menu-icon-1');
        const menuIcon2 = document.getElementById('menu-icon-2');
        const mobileMenu = document.getElementById('mobile-menu');

        if (menuBtn && mobileMenu) {
            menuBtn.addEventListener('click', () => {
                const isClosed = mobileMenu.classList.contains('hidden');
                if (isClosed) {
                    mobileMenu.classList.remove('hidden');
                    setTimeout(() => {
                        mobileMenu.classList.remove('opacity-0', 'scale-95');
                        mobileMenu.classList.add('opacity-100', 'scale-100');
                    }, 10);
                    menuIcon1.classList.add('rotate-45', 'translate-y-1');
                    menuIcon2.classList.add('-rotate-45', '-translate-y-[3px]');
                } else {
                    mobileMenu.classList.add('opacity-0', 'scale-95');
                    mobileMenu.classList.remove('opacity-100', 'scale-100');
                    setTimeout(() => {
                        mobileMenu.classList.add('hidden');
                    }, 200);
                    menuIcon1.classList.remove('rotate-45', 'translate-y-1');
                    menuIcon2.classList.remove('-rotate-45', '-translate-y-[3px]');
                }
            });

            mobileMenu.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    mobileMenu.classList.add('opacity-0', 'scale-95');
                    mobileMenu.classList.remove('opacity-100', 'scale-100');
                    setTimeout(() => {
                        mobileMenu.classList.add('hidden');
                    }, 200);
                    menuIcon1.classList.remove('rotate-45', 'translate-y-1');
                    menuIcon2.classList.remove('-rotate-45', '-translate-y-[3px]');
                });
            });
        }

        const revealItems = document.querySelectorAll('.reveal-item');
        if ('IntersectionObserver' in window) {
            const revealObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.classList.add('revealed');
                        }, index * 60);
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.05,
                rootMargin: '0px 0px -40px 0px'
            });
            revealItems.forEach(item => revealObserver.observe(item));
        } else {
            revealItems.forEach(item => item.classList.add('revealed'));
        }

        const categoryBtns = document.querySelectorAll('.category-btn');
        const productCards = document.querySelectorAll('.product-card');

        categoryBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                categoryBtns.forEach(b => {
                    b.classList.remove('bg-zinc-900', 'text-white', 'border-zinc-900');
                    b.classList.add('bg-white', 'text-zinc-600', 'border-zinc-200');
                });
                btn.classList.remove('bg-white', 'text-zinc-600', 'border-zinc-200');
                btn.classList.add('bg-zinc-900', 'text-white', 'border-zinc-900');

                const filterValue = btn.getAttribute('data-filter');

                productCards.forEach((card, i) => {
                    const cardCategory = card.getAttribute('data-category');
                    if (filterValue === 'all' || cardCategory === filterValue) {
                        card.style.display = 'block';
                        setTimeout(() => card.style.opacity = '1', 50 + i * 40);
                    } else {
                        card.style.opacity = '0';
                        setTimeout(() => card.style.display = 'none', 300);
                    }
                });
            });
        });

        const counters = document.querySelectorAll('.counter-number');
        const renderCounter = (target) => {
            const limit = parseInt(target.getAttribute('data-counter'));
            const suffix = target.getAttribute('data-suffix') || '';
            let count = 0;
            const duration = 1800;
            const steps = 40;
            const increment = Math.ceil(limit / steps);
            const stepTime = duration / steps;

            const timer = setInterval(() => {
                count += increment;
                if (count >= limit) {
                    target.textContent = limit.toLocaleString('id-ID') + suffix;
                    clearInterval(timer);
                } else {
                    target.textContent = count.toLocaleString('id-ID') + suffix;
                }
            }, stepTime);
        };

        if ('IntersectionObserver' in window) {
            const counterObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        renderCounter(entry.target);
                        counterObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });
            counters.forEach(counter => counterObserver.observe(counter));
        } else {
            counters.forEach(renderCounter);
        }

        document.querySelectorAll('.faq-toggle').forEach(toggle => {
            toggle.addEventListener('click', () => {
                const item = toggle.parentElement;
                const content = item.querySelector('.faq-content');
                const icon = toggle.querySelector('svg');
                const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

                document.querySelectorAll('.faq-item').forEach(otherItem => {
                    if (otherItem !== item) {
                        otherItem.querySelector('.faq-toggle').setAttribute('aria-expanded', 'false');
                        otherItem.querySelector('.faq-content').style.maxHeight = '0px';
                        otherItem.querySelector('.faq-toggle svg').classList.remove('rotate-180');
                    }
                });

                if (isExpanded) {
                    toggle.setAttribute('aria-expanded', 'false');
                    content.style.maxHeight = '0px';
                    icon.classList.remove('rotate-180');
                } else {
                    toggle.setAttribute('aria-expanded', 'true');
                    content.style.maxHeight = content.scrollHeight + 'px';
                    icon.classList.add('rotate-180');
                }
            });
        });
    });
</script>
