import assert from "node:assert/strict";
import test from "node:test";
import mobileMenu from "../../resources/js/storefront/mobile-menu.js";

function eventTarget() {
  const listeners = new Map();

  return {
    addEventListener(type, listener) {
      const entries = listeners.get(type) ?? new Set();
      entries.add(listener);
      listeners.set(type, entries);
    },
    removeEventListener(type, listener) {
      listeners.get(type)?.delete(listener);
    },
    dispatch(type, values = {}) {
      let defaultPrevented = false;
      const event = {
        ...values,
        preventDefault() {
          defaultPrevented = true;
        },
      };
      for (const listener of listeners.get(type) ?? []) {
        listener(event);
      }
      return { ...event, defaultPrevented };
    },
    listenerCount(type) {
      return listeners.get(type)?.size ?? 0;
    },
  };
}

function classList(initial = []) {
  const values = new Set(initial);

  return {
    add(value) {
      values.add(value);
    },
    remove(value) {
      values.delete(value);
    },
    contains(value) {
      return values.has(value);
    },
  };
}

function menuElement(document, { rendered = true } = {}) {
  return {
    isConnected: true,
    getClientRects: () => (rendered ? [{}] : []),
    focus() {
      document.activeElement = this;
    },
  };
}

function fixture({ focusables = null } = {}) {
  const document = eventTarget();
  const mediaQuery = eventTarget();
  const timers = new Map();
  const frames = new Map();
  const ticks = new Map();
  let nextHandle = 1;

  document.body = { classList: classList() };
  document.activeElement = null;

  const opener = menuElement(document);
  const menuButton = menuElement(document);
  const brandLink = menuElement(document);
  const closeButton = menuElement(document);
  const firstLink = menuElement(document);
  const lastLink = menuElement(document);
  const hiddenLink = menuElement(document, { rendered: false });
  const menu = {
    querySelectorAll: () =>
      focusables ?? [brandLink, closeButton, firstLink, lastLink, hiddenLink],
  };
  const backdrop = {};
  const elements = new Map([
    ["#mobile-menu", menu],
    ["#mobile-menu-btn", menuButton],
    ["#mobile-menu-close", closeButton],
    ["#mobile-menu-backdrop", backdrop],
  ]);
  const root = { querySelector: (selector) => elements.get(selector) ?? null };
  const window = {
    matchMedia: () => mediaQuery,
    setTimeout(callback) {
      const handle = nextHandle++;
      timers.set(handle, callback);
      return handle;
    },
    clearTimeout(handle) {
      timers.delete(handle);
    },
    requestAnimationFrame(callback) {
      const handle = nextHandle++;
      frames.set(handle, callback);
      return handle;
    },
    cancelAnimationFrame(handle) {
      frames.delete(handle);
    },
  };
  const component = mobileMenu({ document, window });
  component.$el = root;
  component.$nextTick = (callback) => {
    ticks.set(nextHandle++, callback);
  };
  component.init();

  return {
    component,
    document,
    mediaQuery,
    opener,
    menuButton,
    brandLink,
    closeButton,
    firstLink,
    lastLink,
    timers,
    frames,
    flushTicks() {
      while (ticks.size > 0) {
        for (const [handle, callback] of [...ticks]) {
          ticks.delete(handle);
          callback();
        }
      }
    },
    flushTimers() {
      for (const [handle, callback] of [...timers]) {
        timers.delete(handle);
        callback();
      }
    },
    flushFrames() {
      for (const [handle, callback] of [...frames]) {
        frames.delete(handle);
        callback();
      }
    },
  };
}

test("opening locks scrolling, focuses the close button, and slides in on a frame", () => {
  const { component, document, closeButton, opener, flushTicks, flushFrames } =
    fixture();

  component.toggle({ currentTarget: opener });

  assert.equal(component.isOpen, true);
  assert.equal(component.isVisible, true);
  assert.equal(component.isAnimating, false);
  assert.equal(document.body.classList.contains("overflow-hidden"), true);

  flushTicks();
  assert.equal(
    document.activeElement,
    closeButton,
    "the close button wins over earlier focusable drawer content"
  );

  flushFrames();
  assert.equal(component.isAnimating, true);
});

test("toggling an open menu closes it and restores focus to the trigger", () => {
  const { component, document, opener, flushTicks } = fixture();
  component.toggle({ currentTarget: opener });
  flushTicks();
  document.activeElement = opener;

  component.toggle({ currentTarget: opener });

  assert.equal(component.isOpen, false);
  assert.equal(component.isAnimating, false);
  assert.equal(document.activeElement, opener);
  assert.equal(document.body.classList.contains("overflow-hidden"), false);
});

test("Escape closes the menu, restores the opener, and unmounts after the transition", () => {
  const { component, document, opener, flushTicks, flushTimers } = fixture();
  component.open({ currentTarget: opener });
  flushTicks();

  const event = document.dispatch("keydown", { key: "Escape" });

  assert.equal(event.defaultPrevented, true);
  assert.equal(component.isOpen, false);
  assert.equal(component.isAnimating, false);
  assert.equal(component.isVisible, true);
  assert.equal(document.activeElement, opener);
  assert.equal(document.body.classList.contains("overflow-hidden"), false);

  flushTimers();
  assert.equal(component.isVisible, false);
});

test("closing falls back to the menu button when the opener is no longer visible", () => {
  const { component, document, menuButton } = fixture();
  const staleOpener = menuElement(document, { rendered: false });

  component.open({ currentTarget: staleOpener });
  component.close();

  assert.equal(document.activeElement, menuButton);
});

test("link activation closes without moving focus back to the opener", () => {
  const { component, document, opener, firstLink, flushTicks } = fixture();
  component.open({ currentTarget: opener });
  flushTicks();
  document.activeElement = firstLink;

  component.closeOnLink({
    target: { closest: (selector) => (selector === "a" ? firstLink : null) },
  });

  assert.equal(component.isOpen, false);
  assert.equal(document.activeElement, firstLink);
  assert.equal(document.body.classList.contains("overflow-hidden"), false);
});

test("activation without an anchor inside the drawer keeps it open", () => {
  const { component, opener, flushTicks } = fixture();
  component.open({ currentTarget: opener });
  flushTicks();

  component.closeOnLink({ target: { closest: () => null } });

  assert.equal(component.isOpen, true);
});

test("Tab and Shift+Tab keep focus inside the drawer and skip hidden content", () => {
  const {
    component,
    document,
    brandLink,
    closeButton,
    lastLink,
    opener,
    menuButton,
    flushTicks,
  } = fixture();
  component.open({ currentTarget: opener });
  flushTicks();

  document.activeElement = lastLink;
  const forward = document.dispatch("keydown", { key: "Tab" });
  assert.equal(forward.defaultPrevented, true);
  assert.equal(document.activeElement, brandLink);

  document.activeElement = brandLink;
  const backward = document.dispatch("keydown", {
    key: "Tab",
    shiftKey: true,
  });
  assert.equal(backward.defaultPrevented, true);
  assert.equal(document.activeElement, lastLink);

  document.activeElement = opener;
  document.dispatch("keydown", { key: "Tab" });
  assert.equal(document.activeElement, brandLink);

  document.activeElement = menuButton;
  document.dispatch("keydown", { key: "Tab", shiftKey: true });
  assert.equal(document.activeElement, lastLink);

  document.activeElement = closeButton;
  const middle = document.dispatch("keydown", { key: "Tab" });
  assert.equal(middle.defaultPrevented, false);
  assert.equal(document.activeElement, closeButton);
});

test("a drawer without focusable content keeps focus on the close button", () => {
  const { component, document, closeButton, opener, flushTicks } = fixture({
    focusables: [],
  });
  component.open({ currentTarget: opener });
  flushTicks();

  const event = document.dispatch("keydown", { key: "Tab" });

  assert.equal(event.defaultPrevented, true);
  assert.equal(document.activeElement, closeButton);
});

test("the desktop breakpoint closes the menu and unlocks scrolling without restoring focus", () => {
  const { component, document, opener, closeButton, mediaQuery, flushTicks } =
    fixture();
  component.open({ currentTarget: opener });
  flushTicks();
  document.activeElement = closeButton;

  mediaQuery.dispatch("change", { matches: true });

  assert.equal(component.isOpen, false);
  assert.equal(document.body.classList.contains("overflow-hidden"), false);
  assert.equal(document.activeElement, closeButton);
});

test("reopening during the closing transition keeps the drawer mounted and focused", () => {
  const {
    component,
    document,
    opener,
    closeButton,
    flushTicks,
    flushTimers,
    flushFrames,
  } = fixture();
  component.toggle({ currentTarget: opener });
  flushTicks();

  component.close();
  component.toggle({ currentTarget: opener });
  flushTimers();

  assert.equal(component.isOpen, true);
  assert.equal(component.isVisible, true);
  assert.equal(component.isAnimating, false);

  flushTicks();
  assert.equal(document.activeElement, closeButton);

  flushFrames();
  assert.equal(component.isAnimating, true);
});

test("closing before the queued focus runs does not pull focus into the drawer", () => {
  const { component, document, opener, flushTicks } = fixture();

  component.open({ currentTarget: opener });
  component.close();
  flushTicks();

  assert.equal(component.isOpen, false);
  assert.equal(document.activeElement, opener);
});

test("closing releases a body scroll lock that pre-existed the drawer session", () => {
  const { component, document, opener, flushTicks } = fixture();
  document.body.classList.add("overflow-hidden");

  component.open({ currentTarget: opener });
  flushTicks();
  component.close();

  assert.equal(document.body.classList.contains("overflow-hidden"), false);
});

test("destroy releases document and media-query listeners, timers, frames, and the scroll lock", () => {
  const {
    component,
    document,
    mediaQuery,
    opener,
    timers,
    frames,
    flushTicks,
    flushFrames,
  } = fixture();
  component.open({ currentTarget: opener });
  flushTicks();

  assert.equal(document.listenerCount("keydown"), 1);
  assert.equal(mediaQuery.listenerCount("change"), 1);

  component.destroy();

  assert.equal(document.listenerCount("keydown"), 0);
  assert.equal(mediaQuery.listenerCount("change"), 0);
  assert.equal(document.body.classList.contains("overflow-hidden"), false);
  assert.equal(component.isOpen, false);
  assert.equal(component.isAnimating, false);
  assert.equal(timers.size, 0);
  assert.equal(frames.size, 0);

  flushFrames();
  assert.equal(component.isAnimating, false);

  const event = document.dispatch("keydown", { key: "Escape" });
  assert.equal(event.defaultPrevented, false);
});

test("a header without the drawer elements stays inert", () => {
  const document = eventTarget();
  document.body = { classList: classList() };
  const mediaQuery = eventTarget();
  const window = {
    matchMedia: () => mediaQuery,
    setTimeout,
    clearTimeout,
    requestAnimationFrame: () => 1,
    cancelAnimationFrame: () => {},
  };
  const component = mobileMenu({ document, window });
  component.$el = { querySelector: () => null };

  component.init();
  component.open({ currentTarget: null });

  assert.equal(component.isOpen, false);
  assert.equal(document.listenerCount("keydown"), 0);
  assert.equal(mediaQuery.listenerCount("change"), 0);
});
