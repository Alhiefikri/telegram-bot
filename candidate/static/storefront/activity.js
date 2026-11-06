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
  let dismissed = false;
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
    container.classList.add("opacity-0", "pointer-events-none");
    nextTimer = window.setTimeout(() => {
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
      container.classList.remove("opacity-0", "pointer-events-none");
    });
    scheduleHide();
  };
  const refresh = async () => {
    if (dismissed || document.hidden) return;
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
        container.classList.add("hidden", "opacity-0", "pointer-events-none");
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
  container
    .querySelector("[data-activity-dismiss]")
    ?.addEventListener("click", () => {
      dismissed = true;
      clearTimers();
      container.classList.add("hidden");
      container.dataset.activityState = "hidden";
      container.setAttribute("aria-hidden", "true");
    });
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) clearTimers();
    else if (!dismissed) refresh();
  });
  const refreshTimer = window.setInterval(refresh, 60000);
  window.addEventListener(
    "pagehide",
    () => {
      clearTimers();
      window.clearInterval(refreshTimer);
    },
    { once: true }
  );
  refresh();
}

initVerifiedPurchaseActivity();
