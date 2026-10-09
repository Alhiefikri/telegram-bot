import assert from "node:assert/strict";
import test from "node:test";
import adminDashboard from "../../resources/js/admin-dashboard.js";

function setup(t, initialState = {}, overflow = "") {
  const original = { window: global.window, document: global.document };
  t.after(() => Object.assign(global, original));
  const queue = [];
  const listeners = new Set();
  const watchers = new Map();
  const style = new Map(overflow ? [["overflow", overflow]] : []);
  const media = {
    matches: false,
    addEventListener: (_, listener) => listeners.add(listener),
    removeEventListener: (_, listener) => listeners.delete(listener),
  };
  const document = {
    body: {
      style: {
        getPropertyValue: (key) => style.get(key) ?? "",
        getPropertyPriority: () => "",
        setProperty: (key, value) => style.set(key, value),
        removeProperty: (key) => style.delete(key),
      },
    },
    getElementById: () => dialog,
  };
  const element = () => ({
    isConnected: true,
    visible: true,
    getClientRects() {
      return this.visible ? [{}] : [];
    },
    getAttribute: () => null,
    closest: () => null,
    focus() {
      document.activeElement = this;
    },
  });
  const trigger = element();
  const fallback = element();
  const first = element();
  const last = element();
  const dialog = {
    ...element(),
    querySelectorAll: () => [first, last],
    contains: (target) => [first, last].includes(target),
  };
  const assigned = [];
  global.document = document;
  global.window = {
    matchMedia: () => media,
    getComputedStyle: (element) => ({
      visibility: element.visibility ?? "visible",
    }),
    location: {
      href: "https://example.test/admin?tab=penjualan&sales_filter=waiting&product_form=create#admin-section-sales",
      assign: (url) => assigned.push(String(url)),
    },
  };
  const dashboard = adminDashboard(initialState);
  dashboard.$refs = {
    sidebarTrigger: fallback,
    sidebarDialog: dialog,
    productName: first,
  };
  dashboard.$nextTick = (callback) => queue.push(callback);
  dashboard.$watch = (state, callback) => {
    watchers.set(state, callback);
    return () => watchers.delete(state);
  };
  dashboard.initNavigation();
  const flush = () => {
    while (queue.length) queue.shift()();
  };
  return {
    dashboard,
    trigger,
    fallback,
    first,
    last,
    dialog,
    document,
    style,
    watchers,
    listeners,
    media,
    assigned,
    flush,
  };
}

test("open/close restores the owned scroll style and visible trigger", (t) => {
  const h = setup(t, {}, "auto");
  h.dashboard.openSidebar(h.trigger);
  assert.equal(h.dashboard.sidebarOpen, true);
  assert.equal(h.style.get("overflow"), "hidden");
  h.flush();
  assert.equal(h.document.activeElement, h.first);
  h.dashboard.closeSidebar();
  assert.equal(h.style.get("overflow"), "auto");
  h.flush();
  assert.equal(h.document.activeElement, h.trigger);
  h.dashboard.openSidebar(h.trigger);
  h.flush();
  h.trigger.visible = false;
  h.dashboard.closeSidebar();
  h.flush();
  assert.equal(h.document.activeElement, h.fallback);
});

test("CSS-hidden or detached openers cannot receive restored focus", (t) => {
  const h = setup(t);
  h.dashboard.openSidebar(h.trigger);
  h.flush();
  h.trigger.visibility = "hidden";
  h.dashboard.closeSidebar();
  h.flush();
  assert.equal(h.document.activeElement, h.fallback);
  h.dashboard.openSidebar(h.trigger);
  h.flush();
  h.fallback.isConnected = false;
  h.dashboard.closeSidebar();
  h.flush();
  assert.equal(h.document.activeElement, h.first);
});

test("does not release another owner's lock or overwrite a changed style", (t) => {
  const h = setup(t, {}, "hidden");
  h.dashboard.openSidebar(h.trigger);
  h.dashboard.closeSidebar();
  assert.equal(h.style.get("overflow"), "hidden");
  h.style.set("overflow", "auto");
  h.dashboard.openSidebar(h.trigger);
  h.style.set("overflow", "clip");
  h.dashboard.destroyNavigation();
  assert.equal(h.style.get("overflow"), "clip");
});

test("desktop transition closes and releases without focusing a hidden opener", (t) => {
  const h = setup(t);
  h.dashboard.openSidebar(h.trigger);
  h.media.matches = true;
  for (const listener of h.listeners) listener();
  h.flush();
  assert.equal(h.dashboard.sidebarOpen, false);
  assert.equal(h.style.has("overflow"), false);
  assert.equal(h.document.activeElement, undefined);
  h.dashboard.openSidebar(h.trigger);
  assert.equal(h.dashboard.sidebarOpen, false);
});

test("destroy cancels pending open/restore focus and unregisters lifecycle listeners", (t) => {
  const h = setup(t);
  h.dashboard.openSidebar(h.trigger);
  h.dashboard.closeSidebar();
  h.dashboard.destroy();
  h.flush();
  assert.equal(h.document.activeElement, undefined);
  assert.equal(h.style.has("overflow"), false);
  assert.equal(h.listeners.size, 0);
  assert.equal(h.watchers.size, 0);
  h.dashboard.openSidebar(h.trigger);
  assert.equal(h.dashboard.sidebarOpen, false);
});

test("destroy while opening releases the lock and cancels first focus", (t) => {
  const h = setup(t);
  h.dashboard.openSidebar(h.trigger);
  h.dashboard.destroyNavigation();
  h.flush();
  assert.equal(h.document.activeElement, undefined);
  assert.equal(h.style.has("overflow"), false);
  assert.equal(h.dashboard.sidebarOpen, false);
});

test("a stale close cannot steal focus from a reopened drawer", (t) => {
  const h = setup(t);
  h.dashboard.openSidebar(h.trigger);
  h.dashboard.closeSidebar();
  h.dashboard.openSidebar(h.trigger);
  h.flush();
  assert.equal(h.document.activeElement, h.first);
  assert.equal(h.style.get("overflow"), "hidden");
});

test("modal precedence rejects opening and closes a drawer before modal focus", (t) => {
  const h = setup(t, { productModalOpen: true });
  h.dashboard.init();
  h.dashboard.focusProductModalField = () => h.first.focus();
  h.dashboard.openSidebar(h.trigger);
  h.flush();
  assert.equal(h.dashboard.sidebarOpen, false);
  assert.equal(h.document.activeElement, h.first);
  h.dashboard.productModalOpen = false;
  h.dashboard.openSidebar(h.trigger);
  h.watchers.get("stockModalOpen")(true);
  h.flush();
  assert.equal(h.dashboard.sidebarOpen, false);
  assert.equal(h.style.has("overflow"), false);
});

test("tab containment wraps both directions and redirects escaped focus", (t) => {
  const h = setup(t);
  h.dashboard.openSidebar(h.trigger);
  h.flush();
  let prevented = 0;
  const event = { currentTarget: h.dialog, preventDefault: () => prevented++ };
  h.first.focus();
  h.dashboard.trapModalFocus({ ...event, shiftKey: true });
  assert.equal(h.document.activeElement, h.last);
  h.dashboard.trapModalFocus(event);
  assert.equal(h.document.activeElement, h.first);
  h.dashboard.keepModalFocus({ target: h.trigger }, "admin-dashboard-drawer");
  assert.equal(h.document.activeElement, h.first);
  assert.equal(prevented, 2);
});

test("navigation releases lock and preserves full-page query/hash and create intent", (t) => {
  const h = setup(t);
  h.dashboard.openSidebar(h.trigger);
  h.dashboard.goToTab("produk", true);
  assert.equal(
    h.assigned[0],
    "https://example.test/admin?tab=produk&sales_filter=waiting&product_form=create#admin-section-sales"
  );
  h.dashboard.goToTab("broadcast");
  assert.equal(
    h.assigned[1],
    "https://example.test/admin?tab=broadcast&sales_filter=waiting#admin-section-sales"
  );
  h.flush();
  assert.equal(h.document.activeElement, undefined);
  assert.equal(h.style.has("overflow"), false);
  h.dashboard.openSidebar(h.trigger);
  h.dashboard.handleSidebarNavigation({
    target: { closest: () => ({ href: "/admin/reports" }) },
  });
  assert.equal(h.dashboard.sidebarOpen, false);
});
