const COPY_RESET_DELAY_MS = 1600;
const EMPTY_IMAGE_FILE_NAME = "Tidak ada file yang dipilih";
const RETURN_TARGET_HIGHLIGHT_MS = 1800;
const MAX_ACCOUNT_CARDS = 20;
const ACCOUNT_INPUT_SWITCH_CONFIRMATION =
  "Beralih ke Input Cepat? Draf akun yang belum disimpan tetap berada di halaman sampai modal ditutup.";
const ACCOUNT_INPUT_CLOSE_CONFIRMATION =
  "Tutup Input Akun? Draf akun yang belum disimpan akan hilang.";
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
  productImageUrl: "",
  telegramProductImageUrl: "",
  telegramProductImageFileName: EMPTY_IMAGE_FILE_NAME,
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

const emptyAccountCard = () => ({
  id: newOperationKey(),
  email: "",
  password: "",
  accessMethod: "none",
  accessValue: "",
});

const emptyAccountInputState = () => ({
  accountCards: [emptyAccountCard()],
  accountInputStage: "edit",
  accountInputReviewed: false,
  accountInputReview: null,
  accountInputReviewing: false,
  accountInputErrors: {},
  accountInputSubmitting: false,
  accountInputOperationKey: "",
  accountInputMessage: "",
  accountInputBuyerLocale: "id",
});

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
    ...emptyAccountInputState(),
    manageStockModalOpen: Boolean(initialState.manageStockModalOpen),
    manageStockProductName: "",
    manageStockProductStock: 0,
    manageStockItems: [],
    manageStockAvailableCount: 0,
    manageStockSummary: { available: 0, reserved: 0, paid: 0 },
    manageStockTruncated: false,
    manageStockAddPayload: null,
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
          this.applyManageStockPayload(
            this.manageStockInitialPayload,
            this.manageStockInitialEditingItemId
          );
          this.$nextTick(() => this.focusManageStockError());
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

    accountInputChanged() {
      this.accountInputStage = "edit";
      this.accountInputReviewed = false;
      this.accountInputReview = null;
      this.accountInputErrors = {};
      this.accountInputMessage = "";
    },

    accountInputDirty() {
      return (
        this.accountCards.length > 1 ||
        this.accountInputReviewed ||
        this.accountCards.some(
          (card) =>
            [card.email, card.password, card.accessValue].some(
              (value) => String(value ?? "") !== ""
            ) || card.accessMethod !== "none"
        )
      );
    },

    confirmAccountInputClose() {
      return (
        !this.accountInputDirty() ||
        window.confirm(ACCOUNT_INPUT_CLOSE_CONFIRMATION)
      );
    },

    accountInputError(index, field) {
      const candidates = [
        `accounts.${index}.${field}`,
        `${index}.${field}`,
        `account.${index}.${field}`,
      ];

      for (const key of candidates) {
        const value = this.accountInputErrors?.[key];
        if (Array.isArray(value) && value.length > 0) {
          return value[0];
        }
        if (typeof value === "string" && value !== "") {
          return value;
        }
      }

      return "";
    },

    accountInputErrorClass(index, field) {
      return this.accountInputError(index, field)
        ? "border-[#ef4444] ring-2 ring-[#ef4444]/30"
        : "";
    },

    accountInputFirstError() {
      const key = Object.keys(this.accountInputErrors ?? {})[0] ?? "";
      const match = key.match(
        /(?:accounts\.)?(\d+)\.(email|password|access_method|access_value)/
      );

      return match ? { index: Number(match[1]), field: match[2] } : null;
    },

    focusAccountInputError() {
      const error = this.accountInputFirstError();
      if (!error) {
        return;
      }

      this.$nextTick(() => {
        document
          .getElementById(
            `account-${error.field.replaceAll("_", "-")}-${error.index}`
          )
          ?.focus();
      });
    },

    addAccountCard() {
      if (this.accountCards.length >= MAX_ACCOUNT_CARDS) {
        return;
      }

      this.accountCards.push(emptyAccountCard());
      this.accountInputChanged();
      this.$nextTick(() =>
        document
          .getElementById(`account-email-${this.accountCards.length - 1}`)
          ?.focus()
      );
    },

    removeAccountCard(index) {
      if (this.accountCards.length <= 1) {
        return;
      }

      this.accountCards.splice(index, 1);
      this.accountInputChanged();
      this.$nextTick(() =>
        document
          .getElementById(
            `account-email-${Math.min(index, this.accountCards.length - 1)}`
          )
          ?.focus()
      );
    },

    setAccountAccessMethod(index, method) {
      const card = this.accountCards[index];
      if (!card) {
        return;
      }

      card.accessMethod = method;
      card.accessValue = "";
      this.accountInputChanged();
    },

    accountAccessLabel(method) {
      return (
        {
          none: "Tidak ada",
          otp_mail_link: "OTP Mail Link",
          auth_token: "Auth Token",
        }[method] ?? "Tidak ada"
      );
    },

    accountAccessDomain(card) {
      if (card?.accessMethod !== "otp_mail_link" || !card.accessValue) {
        return "";
      }

      try {
        const url = new URL(card.accessValue);
        return url.protocol === "https:" && !url.username && !url.password
          ? url.hostname
          : "";
      } catch (error) {
        return "";
      }
    },

    accountInputReviewDuplicateSummary(account) {
      const fields = {
        email: "email",
        access_value: "Auth Token",
      };
      const sources = {
        submission: "pengajuan ini",
        registry: "Credential Identity registry",
      };
      const duplicateFields = (account?.duplicate_fields ?? []).map(
        (field) => fields[field] ?? field
      );
      const duplicateSources = (account?.duplicate_sources ?? []).map(
        (source) => sources[source] ?? source
      );

      if (duplicateSources.length === 0) {
        return "Tidak ada konflik identitas";
      }

      return `Duplikat ${duplicateFields.join(" + ")} · ${duplicateSources.join(" + ")}`;
    },

    accountInputCanCommit() {
      return (
        this.accountInputReviewed &&
        this.accountInputStage === "review" &&
        this.accountInputReview?.status === "ready"
      );
    },

    accountInputValidationErrors() {
      const errors = {};
      const add = (index, field, message) => {
        const key = `accounts.${index}.${field}`;
        errors[key] ??= [];
        errors[key].push(message);
      };

      if (
        this.accountCards.length < 1 ||
        this.accountCards.length > MAX_ACCOUNT_CARDS
      ) {
        errors.accounts = [
          `Input Akun harus berisi 1 sampai ${MAX_ACCOUNT_CARDS} akun.`,
        ];
      }

      this.accountCards.forEach((card, index) => {
        const email = String(card.email ?? "");
        const password = String(card.password ?? "");
        const method = String(card.accessMethod ?? "");
        const accessValue = String(card.accessValue ?? "");
        if (email.trim() === "") {
          add(index, "email", "Email wajib diisi.");
        } else if (email.length > 254) {
          add(index, "email", "Email maksimal 254 karakter.");
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
          add(index, "email", "Email tidak valid.");
        }

        if (password.trim() === "") {
          add(index, "password", "Password wajib diisi.");
        } else if (password.length > 1024) {
          add(index, "password", "Password maksimal 1024 karakter.");
        }

        if (!["none", "otp_mail_link", "auth_token"].includes(method)) {
          add(index, "access_method", "Metode akses tidak valid.");
        } else if (method === "none") {
          if (accessValue.trim() !== "") {
            add(
              index,
              "access_value",
              "Nilai metode akses harus kosong saat memilih Tidak ada."
            );
          }
        } else if (accessValue.trim() === "") {
          add(index, "access_value", "Nilai metode akses wajib diisi.");
        } else if (method === "otp_mail_link") {
          try {
            const url = new URL(accessValue);
            if (
              url.protocol !== "https:" ||
              !url.hostname ||
              url.username ||
              url.password
            ) {
              add(
                index,
                "access_value",
                "OTP Mail Link harus berupa URL HTTPS yang aman."
              );
            }
          } catch (error) {
            add(
              index,
              "access_value",
              "OTP Mail Link harus berupa URL HTTPS yang aman."
            );
          }
          if (accessValue.length > 2048) {
            add(index, "access_value", "OTP Mail Link maksimal 2048 karakter.");
          }
        } else if (accessValue.length > 3500) {
          add(index, "access_value", "Auth Token maksimal 3500 karakter.");
        }
      });

      return errors;
    },

    async reviewAccountInput() {
      const errors = this.accountInputValidationErrors();
      this.accountInputErrors = errors;
      this.accountInputMessage = "";
      this.accountInputReview = null;

      if (Object.keys(errors).length > 0) {
        this.accountInputStage = "edit";
        this.accountInputReviewed = false;
        this.focusAccountInputError();
        return false;
      }

      this.accountInputReviewing = true;
      const payload = this.accountInputPayload();
      this.accountInputOperationKey = payload.operation_key;
      const csrf = document.querySelector('input[name="_token"]')?.value ?? "";

      try {
        const response = await fetch(
          this.stockAccountsReviewAction ||
            `${this.stockAccountsFormAction}/review`,
          {
            method: "POST",
            credentials: "same-origin",
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              "X-CSRF-TOKEN": csrf,
            },
            body: JSON.stringify(payload),
          }
        );
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
          this.accountInputErrors = body.errors ?? {};
          this.accountInputMessage =
            body.message ?? "Review Input Akun tidak dapat dilakukan.";
          this.accountInputStage = "edit";
          this.accountInputReviewed = false;
          this.focusAccountInputError();
          return false;
        }

        const review = body.review ?? body;
        if (!review || !Array.isArray(review.accounts)) {
          this.accountInputMessage =
            "Review server tidak mengembalikan hasil yang valid.";
          this.accountInputStage = "edit";
          this.accountInputReviewed = false;
          return false;
        }

        this.accountInputReview = review;
        this.accountInputStage = "review";
        this.accountInputReviewed = true;
        this.accountInputMessage = body.message ?? "Review server selesai.";
        return review.status === "ready";
      } catch (error) {
        this.accountInputMessage =
          "Koneksi gagal. Draf akun tetap tersimpan di halaman ini.";
        this.accountInputStage = "edit";
        this.accountInputReviewed = false;
        return false;
      } finally {
        this.accountInputReviewing = false;
      }
    },

    editAccountInput() {
      this.accountInputStage = "edit";
      this.accountInputReviewed = false;
      this.accountInputReview = null;
      this.accountInputMessage = "";
      this.$nextTick(() => document.getElementById("account-email-0")?.focus());
    },

    accountInputPayload() {
      return {
        operation_key: this.accountInputOperationKey || newOperationKey(),
        buyer_locale: this.accountInputBuyerLocale,
        accounts: this.accountCards.map((card) => ({
          email: card.email,
          password: card.password,
          access_method: card.accessMethod,
          access_value: card.accessMethod === "none" ? null : card.accessValue,
        })),
      };
    },

    async submitAccountInput(event) {
      event.preventDefault();

      if (!this.accountInputCanCommit()) {
        await this.reviewAccountInput();
        return;
      }

      this.accountInputSubmitting = true;
      this.accountInputErrors = {};
      this.accountInputMessage = "";
      const payload = this.accountInputPayload();
      this.accountInputOperationKey = payload.operation_key;
      const csrf = document.querySelector('input[name="_token"]')?.value ?? "";

      try {
        const response = await fetch(this.stockAccountsFormAction, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrf,
          },
          body: JSON.stringify(payload),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
          this.accountInputErrors = body.errors ?? {};
          this.accountInputMessage =
            body.message ?? "Input Akun tidak dapat disimpan.";
          this.accountInputStage = "edit";
          this.accountInputReviewed = false;
          this.accountInputSubmitting = false;
          this.focusAccountInputError();
          return;
        }

        const count = Number(body.item_count ?? this.accountCards.length);
        this.accountInputSubmitting = false;
        this.accountInputMessage = `Stok bertambah ${count} akun.`;
        this.closeStockModal(true);
        window.location.assign(body.redirect_url ?? this.stockReturnUrl);
      } catch (error) {
        this.accountInputMessage =
          "Koneksi gagal. Draf akun tetap tersimpan di halaman ini.";
        this.accountInputSubmitting = false;
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
    },

    openProductFamilyEditModal(family) {
      this.familyModalOpener = document.activeElement;
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
    },

    resetProductFamilyForm() {
      Object.assign(this, {
        familyFormSubmitting: false,
        familyFormAction: this.familyCreateUrl,
        ...emptyProductFamilyForm(),
      });
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
        telegramProductImageFileName: EMPTY_IMAGE_FILE_NAME,
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

    openManageStockModal(product) {
      this.manageStockModalOpener = document.activeElement;
      this.manageStockInitialPayload = null;
      this.manageStockInitialEditingItemId = null;
      this.manageStockErrors = {};
      Object.assign(this, {
        activeTab: "produk",
        manageStockModalOpen: true,
      });
      this.applyManageStockPayload(product);
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
      this.manageStockInitialPayload = null;
      this.manageStockInitialEditingItemId = null;
      this.manageStockErrors = {};
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
        ...emptyAccountInputState(),
        stockModalOpen: true,
      });
      this.$nextTick(() => this.$refs.stockItems?.focus());
    },

    closeStockModal(force = false) {
      if (!force && !this.confirmAccountInputClose()) {
        return;
      }

      const opener = this.stockModalOpener;
      this.stockFormSubmitting = false;
      this.accountInputSubmitting = false;
      this.stockModalOpen = false;
      this.stockItems = "";
      this.stockOperationKey = "";
      this.stockInputFormat = "legacy";
      this.stockInputMode = "quick";
      Object.assign(this, emptyAccountInputState());
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
