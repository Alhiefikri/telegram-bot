const COPY_RESET_DELAY_MS = 1600;
const EMPTY_IMAGE_FILE_NAME = "Tidak ada file yang dipilih";
const RETURN_TARGET_HIGHLIGHT_MS = 1800;
const MODAL_FOCUSABLE_SELECTOR = [
  "a[href]",
  "button:not([disabled])",
  'input:not([disabled]):not([type="hidden"])',
  "select:not([disabled])",
  "textarea:not([disabled])",
  '[tabindex]:not([tabindex="-1"])',
].join(",");

const emptyProductForm = () => ({
  productModalMode: "create",
  productFormMethod: "",
  productId: null,
  productName: "",
  productPrice: "",
  productUsdtPrice: "",
  productCategoryId: "",
  productDescription: "",
  productDeliveryNote: "",
  productUsageGuideTitle: "",
  productUsageGuideUrl: "",
  productIsActive: true,
  productImageUrl: "",
  productImageFileName: EMPTY_IMAGE_FILE_NAME,
});

const STOCK_GROUPS = [
  {
    key: "ready",
    label: "READY / TERSEDIA",
    helper:
      "Akun belum dipakai. Ini prioritas paling atas untuk diedit, dihapus, atau dikirim.",
    statuses: ["TERSEDIA"],
    color: "#38bdf8",
  },
  {
    key: "reserved",
    label: "DIPESAN",
    helper:
      "Sedang ditahan oleh order aktif/pending. Bisa dilepas lewat tombol batal order.",
    statuses: ["DIPESAN"],
    color: "#facc15",
  },
  {
    key: "repair",
    label: "PERLU REPAIR",
    helper:
      "Order expired/cancelled masih menempel ke stok. Lepas dulu sebelum dipakai lagi.",
    statuses: ["EXPIRED", "CANCELLED"],
    color: "#fb7185",
  },
  {
    key: "other",
    label: "STATUS LAIN",
    helper: "Riwayat status selain ready, dipesan, repair, dan paid.",
    statuses: [],
    color: "#c4b5fd",
  },
  {
    key: "paid",
    label: "PAID",
    helper:
      "Sudah terjual/terkonfirmasi. Grup ini sengaja ditaruh paling bawah.",
    statuses: ["PAID"],
    color: "#7BD10A",
  },
];

const stockGroupFor = (status) =>
  STOCK_GROUPS.find((group) => group.statuses.includes(status)) ??
  STOCK_GROUPS.find((group) => group.key === "other");

const stockGroupRank = (status) =>
  STOCK_GROUPS.findIndex((group) => group.key === stockGroupFor(status).key);

const editableStockItems = (items = []) =>
  [...items]
    .sort(
      (first, second) =>
        stockGroupRank(first.status) - stockGroupRank(second.status) ||
        Number(first.id) - Number(second.id)
    )
    .map((item, index) => ({
      ...item,
      editing: false,
      displayIndex: index + 1,
      groupKey: stockGroupFor(item.status).key,
    }));

export function salesFilter() {
  return {
    salesFilter: "all",
    isSalesFilterActive(key) {
      return this.salesFilter === key;
    },
    orderVisible(element) {
      return (
        this.salesFilter === "all" ||
        element.dataset.orderFilterGroups.split(" ").includes(this.salesFilter)
      );
    },
  };
}

export default function adminDashboard(initialState = {}) {
  return {
    activeTab: initialState.activeTab ?? "stats",
    copied: false,
    storeLink: initialState.storeLink ?? "",
    copyState: "Salin",
    productModalOpen: Boolean(initialState.productModalOpen),
    productFormSubmitting: false,
    productFormAction: initialState.productStoreUrl ?? "",
    ...emptyProductForm(),
    ...(initialState.productForm ?? {}),
    productDeliveryNoteOpen: Boolean(
      initialState.productForm?.productDeliveryNoteOpen
    ),
    stockModalOpen: Boolean(initialState.stockModalOpen),
    stockFormAction: initialState.stockForm?.stockFormAction ?? "",
    stockProductId: initialState.stockForm?.stockProductId ?? null,
    stockProductName: initialState.stockForm?.stockProductName ?? "",
    stockProductStock: initialState.stockForm?.stockProductStock ?? 0,
    stockItems: initialState.stockForm?.stockItems ?? "",
    stockFormSubmitting: false,
    manageStockModalOpen: false,
    manageStockProductName: "",
    manageStockProductStock: 0,
    manageStockItems: [],
    manageStockAvailableCount: 0,
    manageStockSummary: { available: 0, reserved: 0, paid: 0 },
    manageStockTruncated: false,
    manageStockAddPayload: null,
    productModalOpener: null,
    stockModalOpener: null,
    manageStockModalOpener: null,
    moreMenuOpen: false,
    moreMenuOpener: null,
    paymentSwitchOpen: false,
    paymentSwitchForm: null,
    paymentSwitchOpener: null,
    paymentSwitchCurrentLabel: "",
    paymentSwitchTargetLabel: "",

    init() {
      this.$nextTick(() => {
        if (this.productModalOpen) {
          this.$refs.productName?.focus();
          return;
        }

        if (this.stockModalOpen) {
          this.$refs.stockItems?.focus();
          return;
        }

        this.focusReturnTarget();
      });
    },
    openMoreMenu() {
      this.moreMenuOpener = document.activeElement;
      this.moreMenuOpen = true;
      this.$nextTick(() => this.$refs.moreMenuFirst?.focus());
    },

    closeMoreMenu(restoreFocus = true) {
      const opener = this.moreMenuOpener;
      this.moreMenuOpen = false;
      this.moreMenuOpener = null;

      if (restoreFocus) {
        this.restoreModalFocus(opener);
      }
    },

    openPaymentProviderSwitch(event) {
      const form = event.currentTarget;
      this.paymentSwitchForm = form;
      this.paymentSwitchOpener = document.activeElement;
      this.paymentSwitchCurrentLabel =
        form.dataset.providerSwitchFrom ?? "Provider aktif";
      this.paymentSwitchTargetLabel =
        form.dataset.providerSwitchTarget ?? "Provider baru";
      this.paymentSwitchOpen = true;
      this.$nextTick(() => this.$refs.paymentSwitchConfirm?.focus());
    },

    closePaymentProviderSwitch(restoreFocus = true) {
      const opener = this.paymentSwitchOpener;
      this.paymentSwitchOpen = false;
      this.paymentSwitchForm = null;
      this.paymentSwitchOpener = null;
      this.paymentSwitchCurrentLabel = "";
      this.paymentSwitchTargetLabel = "";

      if (restoreFocus) {
        this.restoreModalFocus(opener);
      }
    },

    submitPaymentProviderSwitch() {
      const form = this.paymentSwitchForm;
      if (!form) {
        return;
      }

      this.paymentSwitchOpen = false;
      this.paymentSwitchForm = null;
      this.paymentSwitchOpener = null;
      HTMLFormElement.prototype.submit.call(form);
    },

    goToTabFromMore(tab, openProductForm = false) {
      this.closeMoreMenu(false);
      this.goToTab(tab, openProductForm);
    },

    goToTab(tab, openProductForm = false) {
      const url = new URL(window.location.href);
      url.searchParams.set("tab", tab);

      if (openProductForm) {
        url.searchParams.set("product_form", "create");
      } else {
        url.searchParams.delete("product_form");
      }

      window.location.assign(url);
    },

    focusReturnTarget() {
      let targetId = "";

      try {
        targetId = decodeURIComponent(window.location.hash.replace(/^#/, ""));
      } catch (error) {
        targetId = "";
      }

      if (!targetId) {
        return;
      }

      const fallbackId = targetId.startsWith("sales-proof-")
        ? "admin-section-sales"
        : null;
      const target =
        document.getElementById(targetId) ??
        (fallbackId ? document.getElementById(fallbackId) : null);
      if (!target?.matches("[data-admin-return-target]")) {
        return;
      }

      const reduceMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
      ).matches;
      target.scrollIntoView({ block: "center", behavior: "auto" });
      target.focus({ preventScroll: true });
      target.classList.add("admin-return-target-highlight");

      window.setTimeout(
        () => target.classList.remove("admin-return-target-highlight"),
        reduceMotion ? 900 : RETURN_TARGET_HIGHLIGHT_MS
      );
    },

    stockItemCount() {
      return this.stockItems
        .split(/\r?\n/)
        .map((row) => row.trim())
        .filter(Boolean).length;
    },

    manageStockGroups() {
      return STOCK_GROUPS.map((group) => ({
        ...group,
        items: this.manageStockItems.filter(
          (item) => item.groupKey === group.key
        ),
      })).filter((group) => group.items.length > 0);
    },

    modalFocusableElements(dialog) {
      return Array.from(
        dialog?.querySelectorAll(MODAL_FOCUSABLE_SELECTOR) ?? []
      ).filter(
        (element) =>
          element.getClientRects().length > 0 &&
          element.getAttribute("aria-hidden") !== "true"
      );
    },

    trapModalFocus(event) {
      const focusable = this.modalFocusableElements(event.currentTarget);
      const first = focusable[0];
      const last = focusable.at(-1);

      if (!first || !last) {
        event.preventDefault();
        event.currentTarget.focus();
        return;
      }

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    },

    keepModalFocus(event, modalId) {
      const dialog = document.getElementById(modalId);

      if (
        !dialog ||
        dialog.getClientRects().length === 0 ||
        dialog.contains(event.target)
      ) {
        return;
      }

      (this.modalFocusableElements(dialog)[0] ?? dialog).focus();
    },

    restoreModalFocus(opener) {
      this.$nextTick(() => {
        if (opener?.isConnected) {
          opener.focus();
        }
      });
    },

    openProductForm() {
      this.productModalOpener = document.activeElement;
      Object.assign(this, {
        activeTab: "produk",
        productFormAction: initialState.productStoreUrl ?? "",
        productFormSubmitting: false,
        ...emptyProductForm(),
        productModalOpen: true,
      });
      this.$nextTick(() => this.$refs.productName?.focus());
    },

    openProductEditModal(product) {
      this.productModalOpener = document.activeElement;
      Object.assign(this, {
        activeTab: "produk",
        productModalMode: "edit",
        productFormAction: product.updateUrl,
        productFormSubmitting: false,
        productFormMethod: "PATCH",
        productId: product.id,
        productName: product.name,
        productPrice: product.priceAmount,
        productUsdtPrice: product.usdtPriceAmount ?? "",
        productCategoryId: product.categoryId ?? "",
        productDescription: product.description,
        productDeliveryNote: product.deliveryNote ?? "",
        productUsageGuideTitle: product.usageGuideTitle ?? "",
        productUsageGuideUrl: product.usageGuideUrl ?? "",
        productIsActive: product.isActive,
        productImageUrl: product.imageUrl ?? "",
        productImageFileName: EMPTY_IMAGE_FILE_NAME,
        productDeliveryNoteOpen:
          String(product.deliveryNote || "").trim().length > 0,
        productModalOpen: true,
      });
      this.$nextTick(() => this.$refs.productName?.focus());
    },

    closeProductModal() {
      const opener = this.productModalOpener;
      this.productFormSubmitting = false;
      this.productModalOpen = false;
      this.productModalOpener = null;
      this.restoreModalFocus(opener);
    },

    submitProductForm(event) {
      if (!event.target.checkValidity()) {
        return;
      }

      this.productFormSubmitting = true;
      this.productModalOpen = false;
    },

    openManageStockModal(product) {
      this.manageStockModalOpener = document.activeElement;
      Object.assign(this, {
        activeTab: "produk",
        manageStockProductName: product.name,
        manageStockProductStock: product.stock,
        manageStockItems: editableStockItems(product.stockItems),
        manageStockAvailableCount: product.availableStockItemCount || 0,
        manageStockSummary: product.stockSummary || {
          available: product.availableStockItemCount || 0,
          reserved: 0,
          paid: 0,
        },
        manageStockTruncated: Boolean(product.stockItemsTruncated),
        manageStockAddPayload: {
          id: product.id,
          name: product.name,
          stock: product.stock,
          stockUrl: product.stockUrl,
        },
        manageStockModalOpen: true,
      });
      this.$nextTick(() =>
        this.modalFocusableElements(
          document.getElementById("manage-stock-modal")
        )[0]?.focus()
      );
    },

    closeManageStockModal(restoreFocus = true) {
      const opener = this.manageStockModalOpener;
      this.manageStockModalOpen = false;
      this.manageStockItems = [];
      this.manageStockSummary = { available: 0, reserved: 0, paid: 0 };
      this.manageStockAddPayload = null;
      this.manageStockModalOpener = null;

      if (restoreFocus) {
        this.restoreModalFocus(opener);
      }
    },

    openStockModalFromManage() {
      if (!this.manageStockAddPayload) {
        return;
      }

      const product = this.manageStockAddPayload;
      const opener = this.manageStockModalOpener;
      this.closeManageStockModal(false);
      this.openStockModal(product, opener);
    },

    openStockModal(product, opener = document.activeElement) {
      this.stockModalOpener = opener;
      Object.assign(this, {
        activeTab: "produk",
        stockFormAction: product.stockUrl,
        stockProductId: product.id,
        stockProductName: product.name,
        stockProductStock: product.stock,
        stockItems: "",
        stockFormSubmitting: false,
        stockModalOpen: true,
      });
      this.$nextTick(() => this.$refs.stockItems?.focus());
    },

    closeStockModal() {
      const opener = this.stockModalOpener;
      this.stockFormSubmitting = false;
      this.stockModalOpen = false;
      this.stockItems = "";
      this.stockModalOpener = null;
      this.restoreModalFocus(opener);
    },

    submitStockForm(event) {
      if (!event.target.checkValidity()) {
        return;
      }

      this.stockFormSubmitting = true;
      this.stockModalOpen = false;
    },

    handleCloseModal(event) {
      if (event.detail?.modal === "stock-modal") {
        this.closeStockModal();
      }

      if (event.detail?.modal === "product-modal") {
        this.closeProductModal();
      }

      if (event.detail?.modal === "manage-stock-modal") {
        this.closeManageStockModal();
      }
    },

    markStoreLinkCopied() {
      this.copied = true;
      this.copyState = "Tersalin";
      setTimeout(() => {
        this.copied = false;
        this.copyState = "Salin";
      }, COPY_RESET_DELAY_MS);
    },

    async copyText(text) {
      if (!navigator.clipboard?.writeText) {
        throw new Error("clipboard_unavailable");
      }

      await navigator.clipboard.writeText(text);
      this.markStoreLinkCopied();
    },

    async copyStoreLink() {
      try {
        await this.copyText(this.storeLink);
      } catch (error) {
        window.prompt("Salin link toko", this.storeLink);
      }
    },

    async copyProductShareLink(url) {
      try {
        await this.copyText(url);
      } catch (error) {
        window.prompt("Salin link produk", url);
      }
    },

    async shareStore() {
      if (navigator.share) {
        try {
          await navigator.share({ title: "LHIE STORE", url: this.storeLink });
          return;
        } catch (error) {
          if (error?.name === "AbortError") {
            return;
          }
        }
      }

      await this.copyStoreLink();
    },
  };
}
