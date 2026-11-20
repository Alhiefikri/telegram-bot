const fragmentHeader = "X-Storefront-Catalog";
const catalogSelector = "[data-home-catalog]";

function syncSidebar(current, next) {
  const previousLinks = [...current.querySelectorAll("a[data-catalog-link]")];
  const incomingLinks = [...next.querySelectorAll("a[data-catalog-link]")];
  const unchanged =
    previousLinks.length === incomingLinks.length &&
    previousLinks.every(
      (link, index) => link.innerHTML === incomingLinks[index].innerHTML
    );

  if (!unchanged) {
    const scroll = current.querySelector(
      "[data-catalog-family-scroll]"
    )?.scrollTop;
    current.replaceWith(next);
    const list = next.querySelector("[data-catalog-family-scroll]");
    if (list && scroll !== undefined) list.scrollTop = scroll;
    return next;
  }

  previousLinks.forEach((link, index) => {
    const incoming = incomingLinks[index];
    link.href = incoming.href;
    if (incoming.hasAttribute("aria-current")) {
      link.setAttribute("aria-current", "true");
    } else {
      link.removeAttribute("aria-current");
    }
  });
  return current;
}

export default function initHomeCatalogNavigation() {
  if (!document.querySelector(catalogSelector)) return;

  const homePath = window.location.pathname;
  let pending;
  let preload;
  let hoverTimer;
  let renderedUrl = window.location.href;

  function isCatalogUrl(url) {
    return url.origin === window.location.origin && url.pathname === homePath;
  }

  function requestFragment(url) {
    const controller = new AbortController();
    const request = window
      .fetch(url, {
        headers: { [fragmentHeader]: "1" },
        credentials: "same-origin",
        redirect: "error",
        signal: controller.signal,
      })
      .then(async (response) => {
        if (!response.ok || response.headers.get(fragmentHeader) !== "1") {
          throw new Error("Catalog fragment unavailable");
        }
        return response.text();
      });
    return { controller, request };
  }

  function clearPreload() {
    window.clearTimeout(hoverTimer);
    preload?.controller.abort();
    preload = undefined;
  }

  async function navigate(url, historyAction) {
    if (url.href === renderedUrl && !pending) return;
    pending?.abort();
    window.clearTimeout(hoverTimer);

    const canReuse =
      preload?.url === url.href && performance.now() - preload.started < 3000;
    const load = canReuse ? preload : requestFragment(url);
    if (!canReuse) clearPreload();
    preload = undefined;
    const { controller, request } = load;
    pending = controller;

    const catalog = document.querySelector(catalogSelector);
    const results = catalog.querySelector("[data-catalog-results]");
    const sidebar = catalog.querySelector("[data-catalog-sidebar]");
    const status = results.querySelector("[data-catalog-loading]");
    results.setAttribute("aria-busy", "true");
    const indicator = window.setTimeout(() => {
      status.hidden = false;
    }, 150);
    const timeout = window.setTimeout(() => controller.abort(), 5000);

    try {
      const html = await request;
      if (pending !== controller) return;
      const template = document.createElement("template");
      template.innerHTML = html;
      const fragment = template.content.querySelector(
        "[data-catalog-fragment]"
      );
      const nextCatalog = fragment?.querySelector(catalogSelector);
      const nextResults = nextCatalog?.querySelector("[data-catalog-results]");
      const nextSidebar = nextCatalog?.querySelector("[data-catalog-sidebar]");
      const locale = document.querySelector("[data-storefront-locale]");
      const nextLocale = fragment?.querySelector("[data-storefront-locale]");
      const maintenance = document.querySelector("[data-catalog-maintenance]");
      const nextMaintenance = fragment?.querySelector(
        "[data-catalog-maintenance]"
      );
      if (
        !nextResults ||
        !nextSidebar ||
        !locale ||
        !nextLocale ||
        !maintenance ||
        !nextMaintenance ||
        fragment.dataset.locale !== document.documentElement.lang
      ) {
        throw new Error("Unexpected catalog response");
      }

      if (historyAction === "push" && url.href !== window.location.href) {
        window.history.pushState(null, "", url);
      }
      renderedUrl = url.href;
      const restoreFocus = catalog.contains(document.activeElement);
      locale.replaceWith(nextLocale);
      maintenance.replaceWith(nextMaintenance);
      const activeSidebar = syncSidebar(sidebar, nextSidebar);
      results.replaceWith(nextResults);
      document
        .querySelector('meta[name="robots"]')
        ?.setAttribute("content", fragment.dataset.robots);
      if (restoreFocus && !catalog.contains(document.activeElement)) {
        const selection = activeSidebar.querySelector(
          '[data-catalog-link][aria-current="true"]'
        );
        (selection?.getClientRects().length ? selection : catalog).focus({
          preventScroll: true,
        });
      }
    } catch {
      if (pending !== controller) return;
      if (historyAction === "pop") window.location.reload();
      else window.location.assign(url);
    } finally {
      window.clearTimeout(indicator);
      window.clearTimeout(timeout);
      if (pending === controller) {
        pending = undefined;
        results.removeAttribute("aria-busy");
        status.hidden = true;
      }
    }
  }

  document.addEventListener("click", (event) => {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey
    )
      return;
    const link = event.target.closest?.(
      `${catalogSelector} a[data-catalog-link]`
    );
    if (!link || link.target || link.hasAttribute("download")) return;
    const url = new URL(link.href);
    if (!isCatalogUrl(url)) return;
    event.preventDefault();
    void navigate(url, "push");
  });

  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (
      event.defaultPrevented ||
      !form.matches?.(`${catalogSelector} [data-catalog-form]`)
    )
      return;
    const url = new URL(form.action);
    if (!isCatalogUrl(url)) return;
    for (const [key, value] of new FormData(form)) {
      if (value !== "") url.searchParams.set(key, value);
    }
    event.preventDefault();
    void navigate(url, "push");
  });

  document.addEventListener("change", (event) => {
    if (event.target.matches?.(`${catalogSelector} select[name="family"]`)) {
      event.target.form?.requestSubmit();
    }
  });

  window.addEventListener("popstate", () => {
    const url = new URL(window.location.href);
    if (isCatalogUrl(url)) void navigate(url, "pop");
  });

  // Warm at most one deliberate hover, never all Families on page load.
  document.addEventListener("pointerover", (event) => {
    if (
      event.pointerType !== "mouse" ||
      pending ||
      navigator.connection?.saveData
    )
      return;
    const link = event.target.closest?.(
      `${catalogSelector} nav a[data-catalog-link]`
    );
    if (!link || link.contains(event.relatedTarget)) return;
    const url = new URL(link.href);
    if (!isCatalogUrl(url) || url.href === renderedUrl) return;
    clearPreload();
    hoverTimer = window.setTimeout(() => {
      const load = requestFragment(url);
      const entry = { ...load, url: url.href, started: performance.now() };
      preload = entry;
      load.request.catch(() => {
        if (preload === entry) preload = undefined;
      });
    }, 140);
  });
  document.addEventListener("pointerout", (event) => {
    const link = event.target.closest?.(
      `${catalogSelector} nav a[data-catalog-link]`
    );
    if (link && !link.contains(event.relatedTarget))
      window.clearTimeout(hoverTimer);
  });
}
