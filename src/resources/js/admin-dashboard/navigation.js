// Composed lifecycle: the dashboard retains its own init, destroy and activeTab.
export default function dashboardNavigation() {
  let destroyed = false;
  let focusRevision = 0;
  let desktop;
  let breakpointChanged;
  let lockedBody;
  let previousOverflow = [];
  let topbarObserver;
  let topbarStyle;
  let previousTopbarHeight;
  let measuredTopbarHeight;
  const unwatch = [];
  const modalStates = [
    "productModalOpen",
    "stockModalOpen",
    "manageStockModalOpen",
    "categoryModalOpen",
    "familyModalOpen",
    "paymentSwitchOpen",
    "checkoutMaintenanceOpen",
  ];

  const visible = (element) =>
    element?.isConnected &&
    element.getClientRects().length > 0 &&
    !element.closest("[inert]") &&
    window.getComputedStyle(element).visibility === "visible";

  const releaseScroll = () => {
    if (
      lockedBody &&
      lockedBody.style.getPropertyValue("overflow") === "hidden"
    ) {
      lockedBody.style.removeProperty("overflow");
      for (const [property, value, priority] of previousOverflow) {
        if (value) lockedBody.style.setProperty(property, value, priority);
      }
    }
    lockedBody = null;
  };

  return {
    sidebarOpen: false,
    navigationReady: false,
    sidebarOpener: null,

    initNavigation() {
      if (this.navigationReady) return;
      destroyed = false;
      desktop = window.matchMedia("(min-width: 1024px)");
      breakpointChanged = () => {
        if (desktop.matches) this.closeSidebar(false);
      };
      desktop.addEventListener("change", breakpointChanged);
      const topbar = this.$refs?.dashboardTopbar;
      if (topbar && typeof ResizeObserver !== "undefined") {
        topbarStyle = document.documentElement.style;
        previousTopbarHeight = [
          topbarStyle.getPropertyValue("--admin-topbar-height"),
          topbarStyle.getPropertyPriority("--admin-topbar-height"),
        ];
        const measureTopbar = () => {
          if (destroyed) return;
          measuredTopbarHeight = `${topbar.getBoundingClientRect().height}px`;
          topbarStyle.setProperty(
            "--admin-topbar-height",
            measuredTopbarHeight
          );
        };
        measureTopbar();
        topbarObserver = new ResizeObserver(measureTopbar);
        topbarObserver.observe(topbar);
      }
      for (const state of modalStates) {
        if (state in this && this.$watch) {
          unwatch.push(
            this.$watch(state, (open) => {
              if (open) this.closeSidebar(false);
            })
          );
        }
      }
      this.navigationReady = true;
    },

    destroyNavigation() {
      destroyed = true;
      this.closeSidebar(false);
      desktop?.removeEventListener("change", breakpointChanged);
      topbarObserver?.disconnect();
      if (
        topbarStyle &&
        topbarStyle.getPropertyValue("--admin-topbar-height") ===
          measuredTopbarHeight
      ) {
        topbarStyle.removeProperty("--admin-topbar-height");
        if (previousTopbarHeight[0]) {
          topbarStyle.setProperty(
            "--admin-topbar-height",
            ...previousTopbarHeight
          );
        }
      }
      for (const stop of unwatch.splice(0)) stop?.();
      this.navigationReady = false;
    },

    openSidebar(opener = this.$refs.sidebarTrigger) {
      if (
        destroyed ||
        desktop?.matches ||
        this.sidebarOpen ||
        modalStates.some((state) => this[state])
      )
        return;
      this.sidebarOpener = visible(opener) ? opener : this.$refs.sidebarTrigger;
      this.sidebarOpen = true;
      const body = document.body;
      if (body.style.getPropertyValue("overflow") !== "hidden") {
        previousOverflow = ["overflow", "overflow-x", "overflow-y"].map(
          (property) => [
            property,
            body.style.getPropertyValue(property),
            body.style.getPropertyPriority(property),
          ]
        );
        lockedBody = body;
        body.style.setProperty("overflow", "hidden");
      }
      const revision = ++focusRevision;
      this.$nextTick(() => {
        if (destroyed || !this.sidebarOpen || revision !== focusRevision)
          return;
        const dialog = this.$refs.sidebarDialog;
        (this.modalFocusableElements(dialog)[0] ?? dialog)?.focus();
      });
    },

    closeSidebar(restoreFocus = true) {
      const wasOpen = this.sidebarOpen;
      const opener = this.sidebarOpener;
      this.sidebarOpen = false;
      this.sidebarOpener = null;
      releaseScroll();
      const revision = ++focusRevision;
      if (!wasOpen || !restoreFocus || destroyed) return;
      this.$nextTick(() => {
        if (destroyed || this.sidebarOpen || revision !== focusRevision) return;
        const target = visible(opener) ? opener : this.$refs.sidebarTrigger;
        if (visible(target)) target.focus();
      });
    },

    handleSidebarNavigation(event) {
      const link = event.target.closest("a[href]");
      if (!link) return;
      const current = new URL(window.location.href);
      const destination = new URL(link.href, current);
      if (destination.origin === current.origin && !destination.hash) {
        destination.hash = current.hash;
        link.href = destination.href;
      }
      this.closeSidebar(false);
    },
  };
}
