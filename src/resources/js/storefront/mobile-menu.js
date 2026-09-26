const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
const DESKTOP_BREAKPOINT = "(min-width: 1024px)";
const MENU_TRANSITION_MS = 300;
const FRAME_FALLBACK_MS = 16;

function isRendered(element) {
  return Boolean(
    element?.isConnected !== false && element?.getClientRects?.().length > 0
  );
}

function focusableElements(menu) {
  return [...(menu?.querySelectorAll?.(FOCUSABLE_SELECTOR) ?? [])].filter(
    isRendered
  );
}

function firstVisible(...candidates) {
  return candidates.find(isRendered) ?? null;
}

export default function mobileMenu(environment = globalThis) {
  const document = environment.document;
  const window = environment.window ?? environment;
  const setTimer = environment.setTimeout ?? window.setTimeout.bind(window);
  const clearTimer =
    environment.clearTimeout ?? window.clearTimeout.bind(window);
  const requestFrame =
    environment.requestAnimationFrame ??
    window.requestAnimationFrame?.bind(window) ??
    ((callback) => setTimer(callback, FRAME_FALLBACK_MS));
  const cancelFrame =
    environment.cancelAnimationFrame ??
    window.cancelAnimationFrame?.bind(window) ??
    ((handle) => clearTimer(handle));

  let menu = null;
  let menuButton = null;
  let closeButton = null;
  let backdrop = null;
  let desktopMediaQuery = null;
  let documentKeydown = null;
  let breakpointChange = null;
  let opener = null;
  let closeTimer = null;
  let openFrame = null;
  let focusGeneration = 0;
  let bodyLockOwned = false;
  let initialized = false;

  function runAfterUpdate(component, callback) {
    if (typeof component.$nextTick === "function") {
      component.$nextTick(callback);
      return;
    }

    callback();
  }

  function clearCloseTimer() {
    if (closeTimer === null) {
      return;
    }

    clearTimer(closeTimer);
    closeTimer = null;
  }

  function cancelOpenFrame() {
    if (openFrame === null) {
      return;
    }

    cancelFrame(openFrame);
    openFrame = null;
  }

  function lockBodyScroll() {
    if (bodyLockOwned || !document.body) {
      return;
    }

    document.body.classList.add("overflow-hidden");
    bodyLockOwned = true;
  }

  function releaseBodyScroll() {
    if (!bodyLockOwned) {
      return;
    }

    document.body?.classList.remove("overflow-hidden");
    bodyLockOwned = false;
  }

  return {
    isOpen: false,
    isVisible: false,
    isAnimating: false,

    init() {
      if (initialized || !document || !window || !this.$el) {
        return;
      }

      menu = this.$el.querySelector("#mobile-menu");
      menuButton = this.$el.querySelector("#mobile-menu-btn");
      closeButton = this.$el.querySelector("#mobile-menu-close");
      backdrop = this.$el.querySelector("#mobile-menu-backdrop");

      if (!menu || !menuButton || !backdrop) {
        return;
      }

      initialized = true;
      documentKeydown = (event) => this.handleKeydown(event);
      document.addEventListener("keydown", documentKeydown);

      desktopMediaQuery = window.matchMedia?.(DESKTOP_BREAKPOINT) ?? null;
      if (desktopMediaQuery) {
        breakpointChange = (event) => {
          if (event.matches) {
            this.close(false);
          }
        };
        desktopMediaQuery.addEventListener("change", breakpointChange);
      }
    },

    destroy() {
      if (documentKeydown) {
        document.removeEventListener("keydown", documentKeydown);
        documentKeydown = null;
      }
      if (desktopMediaQuery && breakpointChange) {
        desktopMediaQuery.removeEventListener("change", breakpointChange);
      }

      desktopMediaQuery = null;
      breakpointChange = null;
      menu = null;
      menuButton = null;
      closeButton = null;
      backdrop = null;
      clearCloseTimer();
      cancelOpenFrame();
      focusGeneration += 1;
      releaseBodyScroll();
      opener = null;
      this.isOpen = false;
      this.isVisible = false;
      this.isAnimating = false;
      initialized = false;
    },

    toggle(event) {
      // The Storefront initializer keeps a second click listener on this button
      // until the parent cutover. It decides from the rendered drawer state, so
      // it would invert every toggle Alpine commits. Stop that listener and own
      // the click here.
      event?.stopImmediatePropagation?.();

      if (this.isOpen) {
        this.close();
        return;
      }

      this.open(event);
    },

    open(event) {
      if (this.isOpen || !menu) {
        return;
      }

      clearCloseTimer();
      cancelOpenFrame();
      opener = event?.currentTarget ?? document.activeElement ?? null;
      this.isOpen = true;
      this.isVisible = true;
      this.isAnimating = false;
      lockBodyScroll();

      const generation = ++focusGeneration;
      runAfterUpdate(this, () => {
        if (!this.isOpen || generation !== focusGeneration) {
          return;
        }

        firstVisible(closeButton, ...focusableElements(menu))?.focus();
      });

      openFrame = requestFrame(() => {
        openFrame = null;
        if (this.isOpen) {
          this.isAnimating = true;
        }
      });
    },

    close(restoreFocus = true) {
      if (!this.isOpen) {
        return;
      }

      focusGeneration += 1;
      cancelOpenFrame();
      this.isOpen = false;
      this.isAnimating = false;
      releaseBodyScroll();

      const returnTarget = opener;
      opener = null;
      if (restoreFocus) {
        firstVisible(returnTarget, menuButton)?.focus();
      }

      clearCloseTimer();
      closeTimer = setTimer(() => {
        closeTimer = null;
        if (!this.isOpen) {
          this.isVisible = false;
        }
      }, MENU_TRANSITION_MS);
    },

    closeOnLink(event) {
      if (event.target?.closest?.("a")) {
        this.close(false);
      }
    },

    handleKeydown(event) {
      if (!this.isOpen) {
        return;
      }

      if (event.key === "Escape") {
        event.preventDefault();
        this.close();
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const focusable = focusableElements(menu);
      if (focusable.length === 0) {
        event.preventDefault();
        firstVisible(closeButton)?.focus();
        return;
      }

      const activeIndex = focusable.indexOf(document.activeElement);
      const lastIndex = focusable.length - 1;

      if (event.shiftKey) {
        if (activeIndex <= 0) {
          event.preventDefault();
          focusable[lastIndex].focus();
        }
        return;
      }

      if (activeIndex === -1 || activeIndex === lastIndex) {
        event.preventDefault();
        focusable[0].focus();
      }
    },
  };
}
