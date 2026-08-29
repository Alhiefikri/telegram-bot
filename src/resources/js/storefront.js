const MODAL_FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
const STOREFRONT_EVENT_URL = "/storefront/events";

function trackStorefrontEvent(action, payload = {}) {
  const csrfToken =
    document
      .querySelector('meta[name="csrf-token"]')
      ?.getAttribute("content") || "";
  const data = new FormData();

  data.append("action", action);
  Object.entries(payload).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== "") {
      data.append(key, value);
    }
  });

  if (csrfToken) {
    data.append("_token", csrfToken);
  }

  if (
    navigator.sendBeacon &&
    navigator.sendBeacon(STOREFRONT_EVENT_URL, data)
  ) {
    return;
  }

  window
    .fetch(STOREFRONT_EVENT_URL, {
      method: "POST",
      body: data,
      credentials: "same-origin",
      keepalive: true,
      headers: csrfToken ? { "X-CSRF-TOKEN": csrfToken } : {},
    })
    .catch(() => {});
}

function toggleMobileMenu(
  menu,
  iconTop,
  iconMiddle,
  iconBottom,
  menuButton,
  backdrop,
  forceClosed = null
) {
  const isClosed =
    menu.classList.contains("hidden") ||
    menu.getAttribute("aria-hidden") !== "false";
  const shouldOpen = forceClosed === null ? isClosed : !forceClosed;

  if (shouldOpen) {
    menu.classList.remove("hidden");
    backdrop?.classList.remove("hidden");
    menu.setAttribute("aria-hidden", "false");
    menuButton?.setAttribute("aria-expanded", "true");
    document.body.classList.add("overflow-hidden");

    window.requestAnimationFrame(() => {
      menu.classList.remove("-translate-x-full");
      menu.classList.add("translate-x-0");
      backdrop?.classList.remove("opacity-0");
      backdrop?.classList.add("opacity-100");
    });

    iconTop?.classList.add("rotate-45", "translate-y-[9px]");
    iconMiddle?.classList.add("opacity-0");
    iconBottom?.classList.add("-rotate-45", "-translate-y-[9px]");
    return;
  }

  menu.classList.add("-translate-x-full");
  menu.classList.remove("translate-x-0");
  backdrop?.classList.add("opacity-0");
  backdrop?.classList.remove("opacity-100");
  menu.setAttribute("aria-hidden", "true");
  menuButton?.setAttribute("aria-expanded", "false");
  document.body.classList.remove("overflow-hidden");

  window.setTimeout(() => {
    menu.classList.add("hidden");
    backdrop?.classList.add("hidden");
  }, 250);

  iconTop?.classList.remove("rotate-45", "translate-y-[9px]");
  iconMiddle?.classList.remove("opacity-0");
  iconBottom?.classList.remove("-rotate-45", "-translate-y-[9px]");
}

function initMobileMenu() {
  const menuButton = document.getElementById("mobile-menu-btn");
  const closeButton = document.getElementById("mobile-menu-close");
  const menuIconTop = document.getElementById("menu-icon-1");
  const menuIconMiddle = document.getElementById("menu-icon-2");
  const menuIconBottom = document.getElementById("menu-icon-3");
  const mobileMenu = document.getElementById("mobile-menu");
  const backdrop = document.getElementById("mobile-menu-backdrop");

  if (!menuButton || !mobileMenu) {
    return;
  }

  const isOpen = () => mobileMenu.getAttribute("aria-hidden") === "false";
  let opener = null;
  const closeMenu = (restoreFocus = true) => {
    if (!isOpen()) {
      return;
    }

    toggleMobileMenu(
      mobileMenu,
      menuIconTop,
      menuIconMiddle,
      menuIconBottom,
      menuButton,
      backdrop,
      true
    );
    if (restoreFocus) {
      (opener || menuButton).focus();
    }
    opener = null;
  };

  menuButton.addEventListener("click", () => {
    if (isOpen()) {
      closeMenu();
      return;
    }

    opener = document.activeElement;
    toggleMobileMenu(
      mobileMenu,
      menuIconTop,
      menuIconMiddle,
      menuIconBottom,
      menuButton,
      backdrop
    );
    window.requestAnimationFrame(() =>
      (
        closeButton || mobileMenu.querySelector(MODAL_FOCUSABLE_SELECTOR)
      )?.focus()
    );
  });

  closeButton?.addEventListener("click", () => closeMenu());
  backdrop?.addEventListener("click", () => closeMenu());

  mobileMenu.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => closeMenu(false));
  });

  document.addEventListener("keydown", (event) => {
    if (!isOpen()) {
      return;
    }

    if (event.key === "Escape") {
      event.preventDefault();
      closeMenu();
      return;
    }

    if (event.key !== "Tab") {
      return;
    }

    const focusable = Array.from(
      mobileMenu.querySelectorAll(MODAL_FOCUSABLE_SELECTOR)
    ).filter((element) => element.offsetParent !== null);
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

function renderCounter(target) {
  const limit = Number.parseInt(target.getAttribute("data-counter"), 10);
  const suffix = target.getAttribute("data-suffix") || "";

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
      target.textContent = limit.toLocaleString("id-ID") + suffix;
      window.clearInterval(timer);
      return;
    }

    target.textContent = count.toLocaleString("id-ID") + suffix;
  }, stepTime);
}

function initCounters() {
  const counters = document.querySelectorAll(".counter-number");

  if (!counters.length) {
    return;
  }

  if (!("IntersectionObserver" in window)) {
    counters.forEach(renderCounter);
    return;
  }

  const counterObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        renderCounter(entry.target);
        counterObserver.unobserve(entry.target);
      });
    },
    { threshold: 0.1 }
  );

  counters.forEach((counter) => counterObserver.observe(counter));
}

function initScrollProgress() {
  const bar = document.getElementById("scroll-progress");

  if (!bar) {
    return;
  }

  let ticking = false;
  const update = () => {
    ticking = false;
    const doc = document.documentElement;
    const max = doc.scrollHeight - window.innerHeight;
    const progress =
      max > 0 ? Math.min(1, Math.max(0, window.scrollY / max)) : 0;
    bar.style.setProperty("--scroll-progress", progress.toFixed(4));
  };
  const requestUpdate = () => {
    if (!ticking) {
      ticking = true;
      window.requestAnimationFrame(update);
    }
  };

  window.addEventListener("scroll", requestUpdate, { passive: true });
  window.addEventListener("resize", requestUpdate, { passive: true });
  update();
}

function initVerifiedPurchaseActivity() {
  const container = document.getElementById("verified-purchase-activity");
  const endpoint = container?.dataset.endpoint;
  const message = container?.querySelector("[data-activity-message]");
  const live = container?.querySelector("[data-activity-live]");
  if (!container || !endpoint || !message) return;

  const DISPLAY_MS = 4_500;
  const TRANSITION_MS = 300;
  const NEXT_GAP_MS = 700;
  let items = [];
  let position = 0;
  let paused = false;
  let initialized = false;
  let hideTimer = null;
  let nextTimer = null;
  const reducedMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)"
  ).matches;
  const clearTimers = () => {
    if (hideTimer !== null) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }
    if (nextTimer !== null) {
      window.clearTimeout(nextTimer);
      nextTimer = null;
    }
  };
  const render = (item, announce = false) => {
    message.textContent = `${item.buyer} membeli ${item.product} · ${item.relative_time}`;
    container.dataset.activityState = "visible";
    container.setAttribute("aria-hidden", "false");
    if (announce)
      live.textContent = `Aktivitas pembelian baru: ${item.buyer} membeli ${item.product}.`;
  };
  const hide = () => {
    if (container.dataset.activityState !== "visible") return;
    container.dataset.activityState = "hidden";
    container.setAttribute("aria-hidden", "true");
    container.classList.add(
      "opacity-0",
      "translate-x-6",
      "pointer-events-none"
    );
    nextTimer = window.setTimeout(() => {
      container.classList.add("hidden");
      nextTimer = null;
      if (!paused && !reducedMotion && items.length > 1) {
        position = (position + 1) % items.length;
        show();
      }
    }, TRANSITION_MS + NEXT_GAP_MS);
  };
  const scheduleHide = () => {
    clearTimers();
    if (reducedMotion || paused || items.length === 0) return;
    hideTimer = window.setTimeout(() => {
      hideTimer = null;
      hide();
    }, DISPLAY_MS);
  };
  const show = (announce = false) => {
    const item = items[position];
    if (!item) return;
    clearTimers();
    container.classList.remove("hidden");
    render(item, announce);
    window.requestAnimationFrame(() => {
      container.classList.remove(
        "opacity-0",
        "translate-x-6",
        "pointer-events-none"
      );
    });
    scheduleHide();
  };
  const refresh = async () => {
    try {
      const response = await window.fetch(endpoint, {
        credentials: "same-origin",
      });
      if (!response.ok) return;
      const next = (await response.json()).items || [];
      const known = new Set(items.map((item) => item.id));
      items = next;
      const newItem = initialized
        ? items.find((item) => !known.has(item.id))
        : null;
      position = newItem
        ? items.indexOf(newItem)
        : Math.min(position, Math.max(0, items.length - 1));
      if (!items.length) {
        clearTimers();
        container.dataset.activityState = "hidden";
        container.setAttribute("aria-hidden", "true");
        container.classList.add(
          "hidden",
          "opacity-0",
          "translate-x-6",
          "pointer-events-none"
        );
        return;
      }
      show(newItem !== null);
      initialized = true;
    } catch {
      /* Public trust UI must fail silently. */
    }
  };
  ["mouseenter", "focusin", "touchstart"].forEach((event) =>
    container.addEventListener(
      event,
      () => {
        paused = true;
        if (hideTimer !== null) {
          window.clearTimeout(hideTimer);
          hideTimer = null;
        }
      },
      { passive: true }
    )
  );
  ["mouseleave", "focusout", "touchend", "touchcancel"].forEach((event) =>
    container.addEventListener(
      event,
      () => {
        paused = false;
        if (container.dataset.activityState === "visible") {
          scheduleHide();
        }
      },
      { passive: true }
    )
  );
  window.setInterval(refresh, 60000);
  refresh();
}

function initNativeQrisImageFallback() {
  document.querySelectorAll("[data-qris-image]").forEach((image) => {
    const fallback = image.getAttribute("data-qris-fallback-src");
    if (!fallback) return;

    const useFallback = () => {
      if (image.dataset.qrisFallbackAttempted === "true") return;
      image.dataset.qrisFallbackAttempted = "true";
      image.src = fallback;
    };

    image.addEventListener("error", useFallback, { once: true });
    if (image.complete && image.naturalWidth === 0) {
      useFallback();
    }
  });
}

function initRevealStagger() {
  const items = document.querySelectorAll(".reveal-item");

  if (!items.length || !("IntersectionObserver" in window)) {
    return;
  }

  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    return;
  }

  const revealObserver = new IntersectionObserver(
    (entries) => {
      let batchIndex = 0;

      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        const target = entry.target;
        target.style.transitionDelay = `${Math.min(batchIndex, 5) * 80}ms`;
        batchIndex += 1;
        target.addEventListener(
          "transitionend",
          () => {
            target.style.transitionDelay = "";
          },
          { once: true }
        );
        target.classList.add("reveal-in");
        revealObserver.unobserve(target);
      });
    },
    { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
  );

  items.forEach((item) => {
    if (item.getBoundingClientRect().top < window.innerHeight * 0.92) {
      return;
    }

    item.classList.add("reveal-armed");
    revealObserver.observe(item);
  });
}

function initFaqAccordion() {
  document.querySelectorAll(".faq-toggle").forEach((toggle) => {
    toggle.addEventListener("click", () => {
      const item = toggle.parentElement;
      const content = item?.querySelector(".faq-content");
      const icon = toggle.querySelector("svg");
      const isExpanded = toggle.getAttribute("aria-expanded") === "true";

      if (!item || !content) {
        return;
      }

      document.querySelectorAll(".faq-item").forEach((otherItem) => {
        if (otherItem === item) {
          return;
        }

        otherItem
          .querySelector(".faq-toggle")
          ?.setAttribute("aria-expanded", "false");
        const otherContent = otherItem.querySelector(".faq-content");
        if (otherContent) {
          otherContent.classList.add("max-h-0");
          otherContent.classList.remove("max-h-[500px]");
        }
        otherItem
          .querySelector(".faq-toggle svg")
          ?.classList.remove("rotate-180");
      });

      if (isExpanded) {
        toggle.setAttribute("aria-expanded", "false");
        content.classList.add("max-h-0");
        content.classList.remove("max-h-[500px]");
        icon?.classList.remove("rotate-180");
        return;
      }

      toggle.setAttribute("aria-expanded", "true");
      content.classList.remove("max-h-0");
      content.classList.add("max-h-[500px]");
      icon?.classList.add("rotate-180");
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
  const feedback = button.querySelector("[data-copy-feedback]");
  const originalFeedback = feedback?.textContent;
  const status = button
    .closest("[data-copy-group]")
    ?.querySelector("[data-copy-status]");
  const statusText = button.getAttribute("data-copy-success") || "Tersalin.";

  if (status) {
    status.textContent = statusText;
  }

  if (feedback) {
    feedback.textContent = "Tersalin";
  } else {
    button.textContent = "Tersalin";
  }

  window.setTimeout(() => {
    if (feedback) {
      feedback.textContent = originalFeedback;
    } else {
      button.textContent = originalText;
    }

    if (status) {
      status.textContent = "";
    }
  }, 1200);
}

function initPaymentActions() {
  document.querySelectorAll("[data-copy-text]").forEach((button) => {
    button.addEventListener("click", async () => {
      if (await writeClipboard(button.getAttribute("data-copy-text"))) {
        markCopied(button);
      }
    });
  });

  document.querySelectorAll("[data-share-text]").forEach((button) => {
    button.addEventListener("click", async () => {
      const text = button.getAttribute("data-share-text") || "";

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

function initSupportFunnelEvents() {
  document
    .querySelectorAll("a[data-support-channel][data-support-category]")
    .forEach((link) => {
      link.addEventListener("click", () => {
        trackStorefrontEvent("storefront.support_selected", {
          order_id: link.getAttribute("data-support-order-id"),
          product_id: link.getAttribute("data-support-product-id"),
          support_channel: link.getAttribute("data-support-channel"),
          support_category: link.getAttribute("data-support-category"),
          signed_url: link.getAttribute("data-support-signed-url"),
          source: "support_link",
        });
      });
    });
}

function initShopPaymentStatus() {
  const containers = document.querySelectorAll("[data-shop-payment-status]");

  if (!containers.length) {
    return;
  }

  const csrfToken =
    document
      .querySelector('meta[name="csrf-token"]')
      ?.getAttribute("content") || "";
  const terminalStatuses = new Set(["expired", "cancelled", "failed"]);
  const secondsLabel = (seconds) =>
    `${Math.max(1, Number.parseInt(seconds, 10) || 60)} detik`;
  const isTerminalStatus = (data) => {
    const paymentStatus = String(data?.payment_status || "").toLowerCase();
    const orderStatus = String(data?.order_status || "").toLowerCase();

    return (
      data?.is_terminal === true ||
      terminalStatuses.has(paymentStatus) ||
      terminalStatuses.has(orderStatus)
    );
  };

  containers.forEach((container) => {
    const statusUrl = container.getAttribute("data-status-url");
    const redirectUrl = container.getAttribute("data-redirect-url");
    const isBinance = container.getAttribute("data-payment-kind") === "binance";
    const isBsc = container.getAttribute("data-payment-kind") === "bsc";
    const title = container.querySelector("[data-payment-status-title]");
    const message = container.querySelector("[data-payment-status-message]");
    const announcement = container.querySelector(
      "[data-payment-status-announcement]"
    );
    const followup = container.querySelector("[data-payment-status-followup]");
    const button = container.querySelector("[data-payment-status-check]");
    let lastAnnouncement = "";
    let waiting = false;

    if (
      !statusUrl ||
      !button ||
      container.getAttribute("data-terminal") === "true"
    ) {
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
      window.setTimeout(
        () => {
          waiting = false;
          if (container.getAttribute("data-terminal") !== "true") {
            button.disabled = false;
          }
        },
        Math.max(1, Number.parseInt(seconds, 10) || 60) * 1000
      );
    };

    button.addEventListener("click", async () => {
      if (waiting) {
        return;
      }

      button.disabled = true;
      setStatus(
        isBsc
          ? "Checking BSC payment"
          : isBinance
            ? "Checking Binance Pay"
            : "Mengecek pembayaran",
        isBsc
          ? "We are checking the approved BSC transfer securely."
          : isBinance
            ? "We are checking your submitted Order ID securely."
            : "Tunggu sebentar, status QRIS sedang dicek."
      );

      try {
        const response = await window.fetch(statusUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...(csrfToken ? { "X-CSRF-TOKEN": csrfToken } : {}),
          },
        });
        const data = await response.json().catch(() => null);

        if (response.status === 429) {
          const retryAfter =
            data?.retry_after || response.headers.get("Retry-After") || 60;
          setStatus(
            "Terlalu sering cek status",
            data?.message || `Coba lagi dalam ${secondsLabel(retryAfter)}.`
          );
          cooldown(retryAfter);
          return;
        }

        if (!data || typeof data !== "object") {
          setStatus(
            "Status belum bisa dibaca",
            "Coba cek lagi beberapa saat lagi."
          );
          button.disabled = false;
          return;
        }

        if (data.is_paid === true) {
          setStatus(
            "Pembayaran terverifikasi",
            data.message ||
              "Invoice akan dibuka ulang untuk menampilkan data akun."
          );
          container.setAttribute("data-terminal", "true");
          window.location.href =
            data.redirect_url || redirectUrl || window.location.href;
          return;
        }

        if (data.should_reload === true) {
          setStatus(
            "QRIS siap dibayar",
            data.message ||
              "Invoice akan dimuat ulang untuk menampilkan QRIS dan total final."
          );
          window.location.href =
            data.redirect_url || redirectUrl || window.location.href;
          return;
        }

        if (data.status === "in_progress") {
          const retryAfter = data.retry_after || 3;
          setStatus(
            data.title || "Status sedang dicek",
            data.message || `Coba lagi dalam ${secondsLabel(retryAfter)}.`
          );
          cooldown(retryAfter);
          return;
        }

        if (isBsc && data.bsc_status) {
          const confirmations =
            Number.parseInt(data.bsc_confirmations, 10) || 0;
          const required =
            Number.parseInt(data.bsc_required_confirmations, 10) || 3;
          setStatus(
            data.bsc_status === "confirming"
              ? `Confirming BSC transfer (${confirmations}/${required})`
              : "BSC payment status",
            data.message ||
              `The BSC transfer currently has ${confirmations}/${required} confirmations.`
          );
          if (data.is_paid === true || data.is_terminal === true) {
            container.setAttribute("data-terminal", "true");
            if (data.is_paid === true) {
              window.location.href =
                data.redirect_url || redirectUrl || window.location.href;
            }
          } else {
            button.disabled = false;
          }
          return;
        }

        if (isTerminalStatus(data)) {
          setStatus(
            "Status pembayaran final",
            data.message ||
              "QRIS ini tidak bisa dibayar lagi. Buat order baru dari katalog."
          );
          container.setAttribute("data-terminal", "true");
          button.hidden = true;
          followup?.classList.remove("hidden");
          if (isBinance && data.is_terminal === true) {
            window.location.href =
              data.redirect_url || redirectUrl || window.location.href;
          }
          return;
        }

        if (data.ok === false) {
          setStatus(
            data.title || "Status belum bisa dicek",
            data.message || "Coba lagi beberapa saat lagi."
          );
          if (data.retry_after) {
            cooldown(data.retry_after);
          } else {
            button.disabled = false;
          }
          return;
        }

        setStatus(
          "Pembayaran belum masuk",
          data.message || "Jika sudah bayar, coba cek lagi beberapa saat lagi."
        );
        button.disabled = false;
      } catch {
        setStatus("Koneksi gagal", "Cek internet lalu coba lagi.");
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
    notes: "",
    buyerName: "",
    termsAccepted: false,
    email: "",
    supportContactChannel: "",
    supportContactValue: "",
    supportContactOpen: false,
    paymentMethod: "qris",
    binanceRail: "binance_pay_id",
    quantityAnnouncement: "",
    modalOpener: null,
    restoreCheckout: null,
    init() {
      const restore = this.$root?.dataset.checkoutRestore;

      if (restore) {
        try {
          this.restoreCheckout = JSON.parse(restore);
        } catch {
          this.restoreCheckout = null;
        }
      }

      if (this.restoreCheckout?.productId) {
        this.$nextTick(() => this.reopenFailedCheckout());

        return;
      }

      const checkoutProduct = this.checkoutProductPayload();
      if (checkoutProduct) {
        this.$nextTick(() =>
          this.openProductModal(checkoutProduct, {
            opener:
              document.getElementById("product-checkout-cta") ||
              document.activeElement,
          })
        );
      }
    },
    openProductModal(product, options = {}) {
      if (!product?.availability?.canOpenModal) {
        return;
      }

      this.modalOpener = options.opener || document.activeElement;
      this.selectedProduct = product;
      this.quantity = Number.parseInt(options.quantity, 10) || 1;
      this.quantityAnnouncement = "";
      this.notes = "";
      this.buyerName = options.buyerName || "";
      this.termsAccepted = Boolean(options.termsAccepted);
      this.supportContactChannel = options.supportContactChannel || "";
      this.email = options.email || "";
      this.supportContactValue = options.supportContactValue || "";
      this.supportContactOpen = Boolean(
        this.supportContactChannel || this.supportContactValue
      );
      this.paymentMethod = this.normalizePaymentMethod(options.paymentMethod);
      this.binanceRail = this.normalizeBinanceRail(options.paymentMethod);
      this.productModalOpen = true;
      trackStorefrontEvent("storefront.checkout_opened", {
        product_id: product?.checkout?.websiteQris?.productId || product?.id,
        source: options.focusInvalid
          ? "checkout_error_restore"
          : "checkout_modal",
      });
      document.body.style.overflow = "hidden";
      this.$nextTick(() => this.focusModal(options.focusInvalid));
    },
    closeProductModal() {
      const opener = this.modalOpener;

      this.productModalOpen = false;
      window.setTimeout(() => {
        this.selectedProduct = null;
        if (opener && typeof opener.focus === "function") {
          opener.focus();
        }
      }, 300);
      document.body.style.overflow = "";
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

      return (
        (Number.parseInt(this.selectedProduct.price?.amount, 10) || 0) *
        (Number.parseInt(this.quantity, 10) || 1)
      );
    },
    selectedUsdtTotal() {
      if (!this.selectedProduct) {
        return 0;
      }

      return (
        (Number.parseInt(this.selectedProduct.price?.usdtAmount, 10) || 0) *
        (Number.parseInt(this.quantity, 10) || 1)
      );
    },
    availableStock() {
      return Math.max(
        1,
        Number.parseInt(this.selectedProduct?.stock?.available, 10) || 1
      );
    },
    formatIdr(amount) {
      return `Rp${Number.parseInt(amount, 10).toLocaleString("id-ID")}`;
    },
    formatUsdt(amount) {
      const microAmount = Number.parseInt(amount, 10);
      if (!Number.isFinite(microAmount) || microAmount < 0) {
        return "—";
      }

      return `${(microAmount / 1_000_000)
        .toFixed(6)
        .replace(/\.?0+$/, "")} USDT`;
    },
    orderedCheckoutChoices() {
      if (!this.selectedProduct) {
        return [];
      }

      return [
        this.selectedProduct.checkout.websiteQris,
        this.selectedProduct.checkout.websiteBinancePay,
      ].filter(Boolean);
    },
    binanceRailChoices() {
      if (!this.selectedProduct) {
        return [];
      }

      return [
        this.selectedProduct.checkout.websiteBinancePayId,
        this.selectedProduct.checkout.websiteUsdtBep20,
      ].filter(Boolean);
    },
    normalizePaymentMethod(method) {
      const requestedMethod = [
        "binance_pay",
        "binance_pay_id",
        "usdt_bep20",
      ].includes(method)
        ? "binance_pay"
        : "qris";
      const availableChoices = this.orderedCheckoutChoices().filter(
        (choice) => choice.enabled
      );

      if (
        availableChoices.some(
          (choice) => this.paymentMethodForChoice(choice) === requestedMethod
        )
      ) {
        return requestedMethod;
      }

      return this.paymentMethodForChoice(availableChoices[0]) || "qris";
    },
    normalizeBinanceRail(method) {
      const available = this.binanceRailChoices().filter(
        (choice) => choice.enabled
      );
      const requested = ["binance_pay_id", "usdt_bep20"].includes(method)
        ? method
        : "binance_pay_id";

      return available.some(
        (choice) => this.paymentMethodForChoice(choice) === requested
      )
        ? requested
        : this.paymentMethodForChoice(available[0]) || "binance_pay_id";
    },
    selectPaymentMethod(method) {
      this.paymentMethod = method;
      if (method === "binance_pay") {
        this.binanceRail = this.normalizeBinanceRail(this.binanceRail);
      }
    },
    cancelBinanceRailSelection() {
      this.paymentMethod = "qris";
    },
    finalPaymentMethod() {
      return this.paymentMethod === "binance_pay"
        ? this.normalizeBinanceRail(this.binanceRail)
        : "qris";
    },
    selectedCheckoutChoice() {
      if (this.paymentMethod !== "binance_pay") {
        return this.selectedProduct?.checkout?.websiteQris;
      }

      return (
        this.binanceRailChoices().find(
          (choice) =>
            this.paymentMethodForChoice(choice) ===
            this.normalizeBinanceRail(this.binanceRail)
        ) || this.selectedProduct?.checkout?.websiteBinancePay
      );
    },
    paymentMethodForChoice(choice) {
      if (["websiteBinancePay", "website_binance_pay"].includes(choice?.key)) {
        return "binance_pay";
      }

      if (
        ["websiteBinancePayId", "website_binance_pay_id"].includes(choice?.key)
      ) {
        return "binance_pay_id";
      }

      if (["websiteUsdtBep20", "website_usdt_bep20"].includes(choice?.key)) {
        return "usdt_bep20";
      }

      return "qris";
    },
    focusModal(focusInvalid = false) {
      const panel = this.$refs.dialogPanel;
      const focusTarget = () => {
        const target = focusInvalid
          ? panel?.querySelector(
              '[aria-invalid="true"], #storefront-buyer-name'
            )
          : panel?.querySelector("#storefront-buyer-name");

        (target || panel)?.focus?.();
      };

      focusTarget();
      window.requestAnimationFrame(focusTarget);
      window.setTimeout(focusTarget, 50);
      window.setTimeout(focusTarget, 300);
    },
    handleModalKeydown(event) {
      if (!this.productModalOpen) {
        return;
      }
      if (event.key === "Escape") {
        event.preventDefault();
        this.closeProductModal();
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const focusable = Array.from(
        this.$refs.dialogPanel?.querySelectorAll(MODAL_FOCUSABLE_SELECTOR) || []
      ).filter((element) => element.offsetParent !== null);

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

      const card = document.getElementById(
        `product-${this.restoreCheckout.productId}`
      );
      const payload =
        card?.dataset?.product ||
        this.checkoutProductPayloadFor(this.restoreCheckout.productId);

      if (!payload) {
        return;
      }

      try {
        this.openProductModal(
          typeof payload === "string" ? JSON.parse(payload) : payload,
          {
            opener:
              card ||
              document.getElementById("product-checkout-cta") ||
              document.activeElement,
            quantity: this.restoreCheckout.quantity,
            buyerName: this.restoreCheckout.buyerName,
            termsAccepted: Boolean(this.restoreCheckout.termsAccepted),
            supportContactChannel: this.restoreCheckout.supportContactChannel,
            email: this.restoreCheckout.email,
            supportContactValue: this.restoreCheckout.supportContactValue,
            focusInvalid: true,
          }
        );
        window.setTimeout(() => this.focusModal(true), 800);
      } catch {
        // Keep the global checkout error visible if the product payload is stale.
      }
    },
    checkoutProductPayload() {
      const payload = this.$root?.dataset.checkoutProduct;

      if (!payload) {
        return null;
      }

      try {
        return JSON.parse(payload);
      } catch {
        return null;
      }
    },
    checkoutProductPayloadFor(productId) {
      const payload = this.checkoutProductPayload();

      return Number.parseInt(payload?.id, 10) === Number.parseInt(productId, 10)
        ? payload
        : null;
    },
  };
}

export function registerStorefrontAlpine(Alpine) {
  Alpine.data("storefrontModal", storefrontModal);
}

export default function initStorefront() {
  const boot = () => {
    document.body.classList.add("js-enabled");

    initMobileMenu();
    initCounters();
    initScrollProgress();
    initVerifiedPurchaseActivity();
    initRevealStagger();
    initFaqAccordion();
    initPaymentActions();
    initSupportFunnelEvents();
    initShopPaymentStatus();
    initNativeQrisImageFallback();
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
    return;
  }

  boot();
}
