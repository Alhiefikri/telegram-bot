import accountInputWorkflow, {
  emptyAccountInputState,
} from "./admin-dashboard/account-input-workflow.js";

const COPY_RESET_DELAY_MS = 1600;
const EMPTY_IMAGE_FILE_NAME = "Tidak ada file yang dipilih";
const RETURN_TARGET_HIGHLIGHT_MS = 1800;
const ACCOUNT_INPUT_SWITCH_CONFIRMATION =
  "Beralih ke Input Cepat? Draf akun yang belum disimpan tetap berada di halaman sampai modal ditutup.";
const MODAL_FOCUSABLE_SELECTOR = [
  "a[href]",
  "button:not([disabled])",
  'input:not([disabled]):not([type="hidden"])',
  "select:not([disabled])",
  "textarea:not([disabled])",
  '[tabindex]:not([tabindex="-1"])',
].join(",");

let productQuantityPriceTierSequence = 0;

const newProductQuantityPriceTier = () => ({
  _key: `product-tier-${++productQuantityPriceTierSequence}`,
  minimumQuantity: "",
  priceAmount: "",
  usdtPriceAmount: "",
});

const normalizeProductQuantityPriceTiers = (tiers) =>
  Array.isArray(tiers)
    ? tiers.map((tier) => ({
        _key:
          tier?._key ?? `product-tier-${++productQuantityPriceTierSequence}`,
        minimumQuantity: tier?.minimumQuantity ?? tier?.minimum_quantity ?? "",
        priceAmount: tier?.priceAmount ?? tier?.price_amount ?? "",
        usdtPriceAmount: tier?.usdtPriceAmount ?? tier?.usdt_price_amount ?? "",
      }))
    : [];

const emptyProductForm = () => ({
  productModalMode: "create",
  productFormMethod: "",
  productId: null,
  productName: "",
  productIcon: "",
  productPrice: "",
  productUsdtPrice: "",
  productCategoryId: "",
  productDescription: "",
  productWarrantyPolicy: "",
  productDeliveryNote: "",
  productUsageGuideTitle: "",
  productUsageGuideUrl: "",
  productIsActive: true,
  productImageFileName: EMPTY_IMAGE_FILE_NAME,
  productImageUrl: "",
  telegramProductImageUrl: "",
  telegramProductImageFileName: EMPTY_IMAGE_FILE_NAME,
  telegramChannelImageUrl: "",
  telegramChannelImageFileName: EMPTY_IMAGE_FILE_NAME,
  productQuantityPriceTiers: [],
  productQuantityPriceTierErrors: {},
});

const emptyCategoryForm = () => ({
  categoryFormMode: "create",
  categoryFormMethod: "",
  categoryId: null,
  categoryName: "",
  categoryDescription: "",
  categoryIsActive: true,
  categorySortOrder: 0,
});

const emptyProductFamilyForm = () => ({
  familyFormMode: "create",
  familyFormMethod: "",
  familyId: null,
  familySlug: "",
  familyName: "",
  familyDescription: "",
  familySortOrder: 0,
  familyImageFileName: EMPTY_IMAGE_FILE_NAME,
  familyImageUrl: "",
  telegramFamilyImageUrl: "",
  telegramFamilyImageFileName: EMPTY_IMAGE_FILE_NAME,
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
    statuses: ["EXPIRED", "CANCELLED", "REPAIR"],
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

const newOperationKey = () => {
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID();
  }

  if (globalThis.crypto?.getRandomValues) {
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, "0"));
    return `${hex.slice(0, 4).join("")}-${hex.slice(4, 6).join("")}-${hex
      .slice(6, 8)
      .join("")}-${hex.slice(8, 10).join("")}-${hex.slice(10).join("")}`;
  }

  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (char) => {
    const random = Math.floor(Math.random() * 16);
    const value = char === "x" ? random : (random & 0x3) | 0x8;
    return value.toString(16);
  });
};

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
    categoryModalOpen: Boolean(initialState.categoryModalOpen),
    categoryFormSubmitting: false,
    categoryFormAction: initialState.categoryCreateUrl ?? "",
    categoryCreateUrl: initialState.categoryCreateUrl ?? "",
    ...emptyCategoryForm(),
    ...(initialState.categoryForm ?? {}),
    productModalOpen: Boolean(initialState.productModalOpen),
    productFormSubmitting: false,
    productFormAction: initialState.productStoreUrl ?? "",
    ...emptyProductForm(),
    ...(initialState.productForm ?? {}),
    productQuantityPriceTiers: normalizeProductQuantityPriceTiers(
      initialState.productForm?.productQuantityPriceTiers ?? []
    ),
    productQuantityPriceTierErrors:
      initialState.productForm?.productQuantityPriceTierErrors ?? {},
    productDeliveryNoteOpen: Boolean(
      initialState.productForm?.productDeliveryNoteOpen
    ),
    categoryModalOpener: null,
    familyModalOpen: Boolean(initialState.familyModalOpen),
    familyFormSubmitting: false,
    familyFormAction: initialState.familyCreateUrl ?? "",
    familyCreateUrl: initialState.familyCreateUrl ?? "",
    ...emptyProductFamilyForm(),
    ...(initialState.familyForm ?? {}),
    stockModalOpen: Boolean(initialState.stockModalOpen),
    stockFormAction: initialState.stockForm?.stockFormAction ?? "",
    stockAccountsFormAction:
      initialState.stockForm?.stockAccountsFormAction ?? "",
    stockAccountsReviewAction:
      initialState.stockForm?.stockAccountsReviewAction ?? "",
    stockReturnUrl: initialState.stockForm?.stockReturnUrl ?? "",
    stockProductId: initialState.stockForm?.stockProductId ?? null,
    stockProductName: initialState.stockForm?.stockProductName ?? "",
    stockProductStock: initialState.stockForm?.stockProductStock ?? 0,
    familyModalOpener: null,
    stockProductDeliveryNote:
      initialState.stockForm?.stockProductDeliveryNote ?? "",
    stockItems: initialState.stockForm?.stockItems ?? "",
    stockOperationKey: initialState.stockForm?.stockOperationKey ?? "",
    stockInputFormat: initialState.stockForm?.stockFormat ?? "legacy",
    structuredStockInputEnabled: Boolean(
      initialState.structuredStockInputEnabled
    ),
    stockFormSubmitting: false,
    stockInputMode: "quick",
    ...accountInputWorkflow({ createOperationKey: newOperationKey }),
    manageStockModalOpen: Boolean(initialState.manageStockModalOpen),
    manageStockProductName: "",
    manageStockProductStock: 0,
    manageStockItems: [],
    manageStockAvailableCount: 0,
    manageStockSummary: { available: 0, reserved: 0, paid: 0 },
    manageStockTruncated: false,
    manageStockAddPayload: null,
    manageStockLoading: false,
    manageStockLoadError: "",
    manageStockLoadPayload: null,
    manageStockLoadEditingItemId: null,
    manageStockRequestId: 0,
    manageStockInitialPayload: initialState.manageStockPayload ?? null,
    manageStockInitialEditingItemId:
      initialState.manageStockEditingItemId ?? null,
    manageStockErrors: initialState.manageStockErrors ?? {},
    productModalOpener: null,
    stockModalOpener: null,
    manageStockModalOpener: null,
    moreMenuOpen: false,
    moreMenuOpener: null,
    paymentSwitchOpen: false,
    paymentSwitchForm: null,
    paymentSwitchOpener: null,
    paymentSwitchCurrentLabel: "",
    checkoutMaintenanceOpen: false,
    checkoutMaintenanceForm: null,
    checkoutMaintenanceOpener: null,
    checkoutMaintenanceConfirmationText: "",
    paymentSwitchTargetLabel: "",

    init() {
      this.$nextTick(() => {
        if (this.productModalOpen) {
          this.focusProductModalField();
          return;
        }

        if (this.stockModalOpen) {
          if (!this.stockOperationKey) {
            this.stockOperationKey = newOperationKey();
          }

          if (this.stockInputMode === "account") {
            this.$nextTick(() =>
              document.getElementById("account-email-0")?.focus()
            );
          } else {
            this.$refs.stockItems?.focus();
          }
          return;
        }

        if (this.manageStockModalOpen && this.manageStockInitialPayload) {
          const payload = this.manageStockInitialPayload;
          const editingItemId = this.manageStockInitialEditingItemId;
          this.manageStockInitialPayload = null;
          this.manageStockLoadPayload = payload;
          this.manageStockLoadEditingItemId = editingItemId;
          this.loadManageStockPayload(payload, editingItemId).then(() => {
            if (!this.manageStockLoadError && this.manageStockModalOpen) {
              this.focusManageStockError();
            }
          });
          return;
        }

        if (this.categoryModalOpen) {
          this.$refs.categoryName?.focus();
          return;
        }

        if (this.familyModalOpen) {
          this.$refs.familyName?.focus();
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

    openCheckoutMaintenanceConfirmation(event) {
      const form = event.currentTarget;
      const target = form.dataset.checkoutMaintenanceTarget;
      const active = form.querySelector('input[name="active"]')?.value === "1";

      this.checkoutMaintenanceForm = form;
      this.checkoutMaintenanceOpener = document.activeElement;
      this.checkoutMaintenanceConfirmationText =
        target === "activate"
          ? "Aktifkan Checkout Maintenance dan tahan pembuatan Order baru?"
          : target === "deactivate"
            ? "Nonaktifkan Checkout Maintenance dan buka checkout baru?"
            : active
              ? "Simpan perubahan catatan Checkout Maintenance?"
              : "Aktifkan Checkout Maintenance dan tahan pembuatan Order baru?";
      this.checkoutMaintenanceOpen = true;
      this.$nextTick(() => this.$refs.checkoutMaintenanceConfirm?.focus());
    },

    closeCheckoutMaintenanceConfirmation(restoreFocus = true) {
      const opener = this.checkoutMaintenanceOpener;
      this.checkoutMaintenanceOpen = false;
      this.checkoutMaintenanceForm = null;
      this.checkoutMaintenanceOpener = null;
      this.checkoutMaintenanceConfirmationText = "";

      if (restoreFocus) {
        this.restoreModalFocus(opener);
      }
    },

    submitCheckoutMaintenanceConfirmation() {
      const form = this.checkoutMaintenanceForm;
      if (!form) {
        return;
      }

      const confirmed = form.querySelector('input[name="confirmed"]');
      if (confirmed) {
        confirmed.value = "1";
      }

      this.checkoutMaintenanceOpen = false;
      this.checkoutMaintenanceForm = null;
      this.checkoutMaintenanceOpener = null;
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
    setStockInputMode(mode) {
      if (this.accountInputSubmitting) {
        return false;
      }
      if (mode !== "quick" && mode !== "account") {
        return false;
      }

      if (mode === this.stockInputMode) {
        return true;
      }

      if (
        this.stockInputMode === "account" &&
        mode === "quick" &&
        this.accountInputDirty() &&
        !window.confirm(ACCOUNT_INPUT_SWITCH_CONFIRMATION)
      ) {
        return false;
      }

      this.stockInputMode = mode;
      this.accountInputMessage = "";

      if (mode === "account" && !this.accountInputOperationKey) {
        this.accountInputOperationKey = newOperationKey();
      }

      this.$nextTick(() => {
        if (mode === "account") {
          document.getElementById("account-email-0")?.focus();
        } else {
          this.$refs.stockItems?.focus();
        }
      });

      return true;
    },

    handleStockInputTabKeydown(event) {
      const key = event.key;
      if (!["ArrowRight", "ArrowLeft", "Home", "End"].includes(key)) {
        return;
      }

      const tabs = Array.from(
        document.querySelectorAll('#stock-modal [role="tab"]')
      );
      const currentIndex = tabs.indexOf(event.currentTarget);
      if (currentIndex < 0 || tabs.length === 0) {
        return;
      }

      const nextIndex =
        key === "Home"
          ? 0
          : key === "End"
            ? tabs.length - 1
            : (currentIndex + (key === "ArrowRight" ? 1 : -1) + tabs.length) %
              tabs.length;
      const nextTab = tabs[nextIndex];

      event.preventDefault();
      if (this.setStockInputMode(nextTab.dataset.stockInputMode)) {
        this.$nextTick(() => nextTab.focus());
      }
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

    manageStockError(field) {
      const value = this.manageStockErrors?.[field];

      if (Array.isArray(value)) {
        return value[0] ?? "";
      }

      return typeof value === "string" ? value : "";
    },

    manageStockErrorMessages() {
      return Object.values(this.manageStockErrors ?? {})
        .flatMap((value) => (Array.isArray(value) ? value : [value]))
        .filter((value) => typeof value === "string" && value !== "");
    },

    focusManageStockError() {
      const itemId = this.manageStockInitialEditingItemId;
      if (itemId === null || itemId === undefined) {
        return;
      }

      this.$nextTick(() => {
        document
          .getElementById("stock-item-editor-" + itemId)
          ?.querySelector('input:not([type="hidden"]), select, textarea')
          ?.focus();
      });
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
    productQuantityPriceTierError(index, field) {
      const messages =
        this.productQuantityPriceTierErrors?.[
          `quantity_price_tiers.${index}.${field}`
        ];

      return Array.isArray(messages) ? (messages[0] ?? "") : (messages ?? "");
    },

    productQuantityPriceTierScheduleError() {
      const messages =
        this.productQuantityPriceTierErrors?.quantity_price_tiers;

      return Array.isArray(messages) ? (messages[0] ?? "") : (messages ?? "");
    },

    addProductQuantityPriceTier() {
      if (this.productQuantityPriceTiers.length >= 5) {
        return;
      }

      this.productQuantityPriceTiers.push(newProductQuantityPriceTier());
    },

    removeProductQuantityPriceTier(index) {
      this.productQuantityPriceTiers.splice(index, 1);
    },

    focusProductModalField() {
      this.$nextTick(() => {
        const dialog = document.getElementById("product-modal");
        const invalidField = dialog?.querySelector(
          '[aria-invalid="true"]:not([type="hidden"])'
        );

        (invalidField ?? this.$refs.productName)?.focus();
      });
    },

    resetImageInput(ref, fileNameField) {
      const input = this.$refs[ref];
      if (input) {
        input.value = "";
      }
      this[fileNameField] = EMPTY_IMAGE_FILE_NAME;
    },

    updateTelegramProductImageFileName(event) {
      this.telegramProductImageFileName =
        event?.target?.files?.[0]?.name ?? EMPTY_IMAGE_FILE_NAME;
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
      this.resetImageInput("productImage", "productImageFileName");
      this.resetImageInput(
        "telegramProductImage",
        "telegramProductImageFileName"
      );
      this.resetImageInput(
        "telegramChannelImage",
        "telegramChannelImageFileName"
      );
      this.$nextTick(() => this.$refs.productName?.focus());
    },
    focusProductFamilyAssignment(familyId) {
      const familyValue = String(familyId);
      const select = Array.from(
        document.querySelectorAll("[data-family-assignment-select]")
      ).find(
        (candidate) =>
          candidate.value === "" &&
          Array.from(candidate.options).some(
            (option) => option.value === familyValue
          )
      );
      if (!select) {
        return;
      }

      const group = select.closest('[id^="admin-product-family-group-"]');
      const toggle = group?.querySelector(
        'button[aria-controls^="admin-product-family-members-"]'
      );
      const focusSelect = () => {
        group?.scrollIntoView({ block: "start" });
        select.value = familyValue;
        select.focus({ preventScroll: true });
      };
      if (toggle?.getAttribute("aria-expanded") !== "true") {
        toggle?.click();
        this.$nextTick(focusSelect);

        return;
      }
      focusSelect();
    },

    openCategoryManagement() {
      this.categoryModalOpener = document.activeElement;
      Object.assign(this, {
        activeTab: "produk",
        categoryModalOpen: true,
        categoryFormSubmitting: false,
        categoryFormAction: this.categoryCreateUrl,
        ...emptyCategoryForm(),
      });
      this.$nextTick(() => this.$refs.categoryName?.focus());
    },

    openCategoryEditModal(category) {
      Object.assign(this, {
        activeTab: "produk",
        categoryModalOpen: true,
        categoryFormSubmitting: false,
        categoryFormMode: "edit",
        categoryFormAction: category.updateUrl,
        categoryFormMethod: "PATCH",
        categoryId: category.id,
        categoryName: category.name,
        categoryDescription: category.description ?? "",
        categoryIsActive: Boolean(category.isActive),
        categorySortOrder: category.sortOrder ?? 0,
      });
      this.$nextTick(() => this.$refs.categoryName?.focus());
    },

    resetCategoryForm() {
      Object.assign(this, {
        categoryFormSubmitting: false,
        categoryFormAction: this.categoryCreateUrl,
        ...emptyCategoryForm(),
      });
      this.$nextTick(() => this.$refs.categoryName?.focus());
    },

    closeCategoryManagement() {
      const opener = this.categoryModalOpener;
      this.categoryFormSubmitting = false;
      this.categoryModalOpen = false;
      this.categoryModalOpener = null;
      this.restoreModalFocus(opener);
    },

    submitCategoryForm(event) {
      if (!event.target.checkValidity()) {
        return;
      }

      this.categoryFormSubmitting = true;
      this.categoryModalOpen = false;
    },

    openProductFamilyForm() {
      this.familyModalOpener = document.activeElement;
      Object.assign(this, {
        activeTab: "produk",
        familyModalOpen: true,
        familyFormSubmitting: false,
        familyFormAction: this.familyCreateUrl,
        ...emptyProductFamilyForm(),
      });
      this.$nextTick(() => this.$refs.familyName?.focus());
      this.resetImageInput("familyImage", "familyImageFileName");
      this.resetImageInput(
        "telegramFamilyImage",
        "telegramFamilyImageFileName"
      );
    },

    openProductFamilyEditModal(family) {
      if (!this.familyModalOpen) {
        this.familyModalOpener = document.activeElement;
      }
      Object.assign(this, {
        activeTab: "produk",
        familyModalOpen: true,
        familyFormSubmitting: false,
        familyFormMode: "edit",
        familyFormAction: family.updateUrl,
        familyFormMethod: "PATCH",
        familyId: family.id,
        familySlug: family.slug ?? "",
        familyName: family.name,
        familyDescription: family.description ?? "",
        familySortOrder: family.sortOrder ?? 0,
        familyImageUrl: family.imageUrl ?? "",
        telegramFamilyImageUrl: family.telegramImageUrl ?? "",
      });
      this.$nextTick(() => this.$refs.familyName?.focus());
      this.resetImageInput("familyImage", "familyImageFileName");
      this.resetImageInput(
        "telegramFamilyImage",
        "telegramFamilyImageFileName"
      );
    },

    resetProductFamilyForm() {
      Object.assign(this, {
        familyFormSubmitting: false,
        familyFormAction: this.familyCreateUrl,
        ...emptyProductFamilyForm(),
      });
      this.resetImageInput("familyImage", "familyImageFileName");
      this.resetImageInput(
        "telegramFamilyImage",
        "telegramFamilyImageFileName"
      );
      this.$nextTick(() => this.$refs.familyName?.focus());
    },

    closeProductFamilyModal() {
      const opener = this.familyModalOpener;
      this.familyFormSubmitting = false;
      this.familyModalOpen = false;
      this.familyModalOpener = null;
      this.restoreModalFocus(opener);
    },

    submitProductFamilyForm(event) {
      if (!event.target.checkValidity()) {
        return;
      }

      this.familyFormSubmitting = true;
      this.familyModalOpen = false;
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
        productIcon: product.icon ?? "",
        productPrice: product.priceAmount,
        productUsdtPrice: product.usdtPriceAmount ?? "",
        productQuantityPriceTiers: normalizeProductQuantityPriceTiers(
          product.quantityPriceTiers ?? []
        ),
        productQuantityPriceTierErrors: {},
        productCategoryId: product.categoryId ?? "",
        productDescription: product.description,
        productWarrantyPolicy: product.warrantyPolicy ?? "",
        productDeliveryNote: product.deliveryNote ?? "",
        productUsageGuideTitle: product.usageGuideTitle ?? "",
        productUsageGuideUrl: product.usageGuideUrl ?? "",
        productIsActive: product.isActive,
        productImageUrl: product.imageUrl ?? "",
        telegramProductImageUrl: product.telegramImageUrl ?? "",
        telegramChannelImageUrl: product.telegramChannelImageUrl ?? "",
        productDeliveryNoteOpen:
          String(product.deliveryNote || "").trim().length > 0,
        productModalOpen: true,
      });
      this.resetImageInput("productImage", "productImageFileName");
      this.resetImageInput(
        "telegramProductImage",
        "telegramProductImageFileName"
      );
      this.resetImageInput(
        "telegramChannelImage",
        "telegramChannelImageFileName"
      );
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

    applyManageStockPayload(product, editingItemId = null) {
      const editingId = editingItemId === null ? null : String(editingItemId);

      Object.assign(this, {
        manageStockProductName: product.name,
        manageStockProductStock: product.stock,
        manageStockItems: editableStockItems(product.stockItems).map(
          (item) => ({
            ...item,
            editing: editingId !== null && String(item.id) === editingId,
          })
        ),
        manageStockAvailableCount: product.availableStockItemCount || 0,
        manageStockSummary: product.stockSummary || {
          available: product.availableStockItemCount || 0,
          reserved: 0,
          paid: 0,
        },
        manageStockTruncated: Boolean(product.stockItemsTruncated),
        manageStockLoadError: "",
        manageStockAddPayload: {
          id: product.id,
          name: product.name,
          stock: product.stock,
          stockUrl: product.stockUrl,
          stockAccountsUrl: product.stockAccountsUrl,
          stockReturnUrl: product.stockReturnUrl,
          deliveryNote: product.deliveryNote,
        },
      });
    },

    async loadManageStockPayload(product, editingItemId = null) {
      const requestId = ++this.manageStockRequestId;
      this.manageStockLoadPayload = product;
      this.manageStockLoadEditingItemId = editingItemId;
      this.manageStockLoadError = "";
      this.manageStockLoading = true;
      this.manageStockItems = [];
      this.manageStockSummary = { available: 0, reserved: 0, paid: 0 };
      this.manageStockTruncated = false;

      try {
        const response = await fetch(product?.stockItemsUrl ?? "", {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
          throw new Error(
            body.message ?? "Data stok produk tidak dapat dimuat."
          );
        }

        if (!Array.isArray(body.stockItems) || !body.stockSummary) {
          throw new Error("Respons data stok produk tidak valid.");
        }

        if (requestId === this.manageStockRequestId) {
          this.applyManageStockPayload({ ...product, ...body }, editingItemId);
        }
      } catch (error) {
        if (requestId === this.manageStockRequestId) {
          this.manageStockLoadError =
            error instanceof Error
              ? error.message
              : "Data stok produk tidak dapat dimuat.";
        }
      } finally {
        if (requestId === this.manageStockRequestId) {
          this.manageStockLoading = false;
        }
      }
    },

    async retryManageStockLoad() {
      if (!this.manageStockLoadPayload) {
        return;
      }

      await this.loadManageStockPayload(
        this.manageStockLoadPayload,
        this.manageStockLoadEditingItemId
      );
    },

    async openManageStockModal(product) {
      this.manageStockModalOpener = document.activeElement;
      this.manageStockInitialPayload = null;
      this.manageStockInitialEditingItemId = null;
      this.manageStockErrors = {};
      Object.assign(this, {
        activeTab: "produk",
        manageStockModalOpen: true,
        manageStockProductName: product.name,
        manageStockProductStock: product.stock,
      });
      await this.loadManageStockPayload(product);
      if (!this.manageStockModalOpen) {
        return;
      }

      this.$nextTick(() =>
        this.modalFocusableElements(
          document.getElementById("manage-stock-modal")
        )[0]?.focus()
      );
    },

    closeManageStockModal(restoreFocus = true) {
      const opener = this.manageStockModalOpener;
      this.manageStockRequestId += 1;
      this.manageStockModalOpen = false;
      this.manageStockLoading = false;
      this.manageStockLoadError = "";
      this.manageStockLoadPayload = null;
      this.manageStockLoadEditingItemId = null;
      this.manageStockItems = [];
      this.manageStockSummary = { available: 0, reserved: 0, paid: 0 };
      this.manageStockAddPayload = null;
      this.manageStockInitialPayload = null;
      this.manageStockInitialEditingItemId = null;
      this.manageStockErrors = {};
      this.manageStockModalOpener = null;

      if (restoreFocus) {
        this.restoreModalFocus(opener);
      }
    },

    openStockModalFromManage() {
      if (!this.manageStockAddPayload || this.manageStockLoading) {
        return;
      }

      const product = this.manageStockAddPayload;
      const opener = this.manageStockModalOpener;
      this.closeManageStockModal(false);
      this.openStockModal(product, opener);
    },

    openStockModal(product, opener = document.activeElement) {
      this.stockModalOpener = opener;
      const nextAccountInputDraftRevision = this.accountInputDraftRevision + 1;
      Object.assign(this, {
        activeTab: "produk",
        stockFormAction: product.stockUrl,
        stockAccountsFormAction: product.stockAccountsUrl ?? "",
        stockAccountsReviewAction: product.stockAccountsReviewUrl ?? "",
        stockReturnUrl: product.stockReturnUrl ?? "",
        stockProductId: product.id,
        stockProductName: product.name,
        stockProductStock: product.stock,
        stockProductDeliveryNote: product.deliveryNote ?? "",
        stockItems: "",
        stockOperationKey: newOperationKey(),
        stockInputFormat: "legacy",
        stockFormSubmitting: false,
        stockInputMode: "quick",
        ...emptyAccountInputState(newOperationKey),
        accountInputDraftRevision: nextAccountInputDraftRevision,
        stockModalOpen: true,
      });
      this.$nextTick(() => this.$refs.stockItems?.focus());
    },
    closeStockModal(force = false) {
      if (this.accountInputSubmitting && !force) {
        return;
      }
      if (!force && !this.confirmAccountInputClose()) {
        return;
      }

      const opener = this.stockModalOpener;
      const nextAccountInputDraftRevision = this.accountInputDraftRevision + 1;
      this.stockFormSubmitting = false;
      this.accountInputSubmitting = false;
      this.stockModalOpen = false;
      this.stockItems = "";
      this.stockOperationKey = "";
      this.stockInputFormat = "legacy";
      this.stockInputMode = "quick";
      Object.assign(this, {
        ...emptyAccountInputState(newOperationKey),
        accountInputDraftRevision: nextAccountInputDraftRevision,
      });
      this.stockModalOpener = null;
      this.restoreModalFocus(opener);
    },

    submitStockForm(event) {
      if (!event.target.checkValidity()) {
        return;
      }

      if (!this.confirmAccountInputClose()) {
        event.preventDefault();
        return;
      }

      if (!this.stockOperationKey) {
        this.stockOperationKey = newOperationKey();
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

      if (event.detail?.modal === "admin-category-management") {
        this.closeCategoryManagement();
      }

      if (event.detail?.modal === "admin-product-family-management") {
        this.closeProductFamilyModal();
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
