const idrFormatter = new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
});

const integerString = /^-?\d+$/;
const maximumTierCount = 5;
const maximumTierQuantity = 20;

function safeInteger(value) {
  if (Number.isSafeInteger(value)) {
    return value;
  }

  if (typeof value !== "string" || !integerString.test(value.trim())) {
    return null;
  }

  const parsed = Number(value);
  return Number.isSafeInteger(parsed) ? parsed : null;
}

function optionalSafeInteger(value) {
  if (value === null || value === undefined || value === "") {
    return { valid: true, value: null };
  }

  const parsed = safeInteger(value);
  return parsed === null
    ? { valid: false, value: null }
    : { valid: true, value: parsed };
}

function formatIdr(amount) {
  return Number.isSafeInteger(amount) ? idrFormatter.format(amount) : "—";
}

function formatUsdt(microAmount) {
  if (!Number.isSafeInteger(microAmount)) {
    return "—";
  }

  return `${(microAmount / 1_000_000).toFixed(6).replace(/\.?0+$/, "")} USDT`;
}

function normalizeTiers(
  rawTiers,
  baseIdrPrice,
  baseUsdtPrice,
  maximumQuantity
) {
  if (
    !Array.isArray(rawTiers) ||
    rawTiers.length > maximumTierCount ||
    !Number.isSafeInteger(baseIdrPrice) ||
    baseIdrPrice <= 0
  ) {
    return null;
  }

  const tiers = [];
  let previousMinimum = 1;
  let previousIdrPrice = baseIdrPrice;
  let previousUsdtPrice = baseUsdtPrice;

  for (const rawTier of rawTiers) {
    if (
      rawTier === null ||
      typeof rawTier !== "object" ||
      Array.isArray(rawTier)
    ) {
      return null;
    }

    const minimumQuantity = safeInteger(rawTier.minimum_quantity);
    const idrPriceResult = optionalSafeInteger(rawTier.price_amount);
    const usdtPriceResult = optionalSafeInteger(rawTier.usdt_price_amount);

    if (
      minimumQuantity === null ||
      minimumQuantity < 2 ||
      minimumQuantity > maximumTierQuantity ||
      minimumQuantity > maximumQuantity ||
      minimumQuantity <= previousMinimum ||
      !idrPriceResult.valid ||
      idrPriceResult.value === null ||
      idrPriceResult.value <= 0 ||
      idrPriceResult.value >= previousIdrPrice ||
      !usdtPriceResult.valid
    ) {
      return null;
    }

    if (
      (previousUsdtPrice === null && usdtPriceResult.value !== null) ||
      (previousUsdtPrice !== null &&
        (usdtPriceResult.value === null ||
          usdtPriceResult.value <= 0 ||
          usdtPriceResult.value >= previousUsdtPrice))
    ) {
      return null;
    }

    tiers.push({
      minimumQuantity,
      idrPrice: idrPriceResult.value,
      usdtPrice: usdtPriceResult.value,
    });
    previousMinimum = minimumQuantity;
    previousIdrPrice = idrPriceResult.value;
    previousUsdtPrice = usdtPriceResult.value;
  }

  return tiers;
}

function normalizePaymentOptions(rawOptions) {
  if (!Array.isArray(rawOptions)) {
    return [];
  }

  return rawOptions
    .filter(
      (option) =>
        option &&
        typeof option === "object" &&
        typeof option.value === "string" &&
        typeof option.label === "string"
    )
    .map((option) => ({
      value: option.value,
      label: option.label,
      description:
        typeof option.description === "string" ? option.description : "",
      provider: typeof option.provider === "string" ? option.provider : "",
      amountKind: option.amountKind === "usdt" ? "usdt" : "idr",
      amountLabel:
        typeof option.amountLabel === "string" ? option.amountLabel : "",
      amountHelp:
        typeof option.amountHelp === "string" ? option.amountHelp : "",
      submitLabel:
        typeof option.submitLabel === "string" ? option.submitLabel : "",
    }));
}

function paymentOptionsFrom(root) {
  const options = Array.from(
    root?.querySelectorAll?.("[data-checkout-payment-option]") ?? []
  ).map(({ dataset }) => ({
    value: dataset.paymentValue,
    label: dataset.paymentLabel,
    description: dataset.paymentDescription,
    provider: dataset.paymentProvider,
    amountKind: dataset.paymentAmountKind,
    amountLabel: dataset.paymentAmountLabel,
    amountHelp: dataset.paymentAmountHelp,
    submitLabel: dataset.paymentSubmitLabel,
  }));

  return normalizePaymentOptions(options);
}

function readText(root, selector) {
  return root?.querySelector?.(selector)?.textContent?.trim() ?? "";
}

function readContactDefaultHelp(root) {
  const value = root?.querySelector?.("[data-checkout-contact-help]")?.dataset
    ?.defaultContactHelp;
  return typeof value === "string" ? value : "";
}

function normalizeItems(rawItems) {
  if (!Array.isArray(rawItems)) {
    return [];
  }

  return rawItems
    .filter((item) => item && typeof item === "object")
    .map((item) => ({
      productId: String(item.productId ?? ""),
      quantity: safeInteger(item.quantity),
      idrUnitPrice: optionalSafeInteger(item.idrUnitPrice).value,
      usdtUnitPrice: optionalSafeInteger(item.usdtUnitPrice).value,
      idrSubtotal: optionalSafeInteger(item.idrSubtotal).value,
      tierMinimum: safeInteger(item.tierMinimum),
    }));
}

function quoteForQuantity(config, value) {
  const quantity = safeInteger(value);
  if (
    !config.quantityEditable ||
    !config.tiers ||
    quantity === null ||
    quantity < config.minimumQuantity ||
    quantity > config.maximumQuantity ||
    config.baseIdrPrice === null
  ) {
    return null;
  }

  let idrUnitPrice = config.baseIdrPrice;
  let usdtUnitPrice = config.baseUsdtPrice;
  let appliedTierMinimum = null;

  for (const tier of config.tiers) {
    if (tier.minimumQuantity > quantity) {
      break;
    }

    idrUnitPrice = tier.idrPrice;
    usdtUnitPrice = tier.usdtPrice;
    appliedTierMinimum = tier.minimumQuantity;
  }

  const idrSubtotal = idrUnitPrice * quantity;
  const usdtSubtotal = usdtUnitPrice === null ? null : usdtUnitPrice * quantity;
  if (
    !Number.isSafeInteger(idrSubtotal) ||
    (usdtSubtotal !== null && !Number.isSafeInteger(usdtSubtotal))
  ) {
    return null;
  }

  return {
    quantity,
    idrUnitPrice,
    idrSubtotal,
    usdtUnitPrice,
    usdtSubtotal,
    appliedTierMinimum,
  };
}

export default function checkoutReview(initialConfig = {}) {
  const quantityEditable = initialConfig.quantityEditable === true;
  const minimumQuantity = safeInteger(initialConfig.minimumQuantity) ?? 1;
  const maximumQuantity = safeInteger(initialConfig.maximumQuantity);
  const baseIdrPrice = optionalSafeInteger(initialConfig.baseIdrPrice);
  const baseUsdtPrice = optionalSafeInteger(initialConfig.baseUsdtPrice);
  const validBaseUsdtPrice =
    baseUsdtPrice.valid &&
    (baseUsdtPrice.value === null || baseUsdtPrice.value > 0);
  const tiers =
    baseIdrPrice.valid && validBaseUsdtPrice
      ? normalizeTiers(
          initialConfig.tiers ?? [],
          baseIdrPrice.value,
          baseUsdtPrice.value,
          maximumQuantity
        )
      : null;
  const config = {
    quantityEditable,
    minimumQuantity,
    maximumQuantity,
    baseIdrPrice:
      baseIdrPrice.valid &&
      baseIdrPrice.value !== null &&
      baseIdrPrice.value > 0
        ? baseIdrPrice.value
        : null,
    baseUsdtPrice: validBaseUsdtPrice ? baseUsdtPrice.value : null,
    tiers,
    items: normalizeItems(initialConfig.items),
    initialIdrSubtotal: optionalSafeInteger(initialConfig.initialIdrSubtotal)
      .value,
    initialUsdtSubtotal: optionalSafeInteger(initialConfig.initialUsdtSubtotal)
      .value,
    initialItemCount: safeInteger(initialConfig.initialItemCount) ?? 0,
    canSubmit: initialConfig.canSubmit === true,
    paymentOptions: normalizePaymentOptions(initialConfig.paymentOptions),
    defaultPaymentMethod:
      typeof initialConfig.selectedPaymentMethod === "string"
        ? initialConfig.selectedPaymentMethod
        : "",
    stickyDefaults: {
      paymentLabel:
        typeof initialConfig.stickyPaymentLabel === "string"
          ? initialConfig.stickyPaymentLabel
          : "Metode pembayaran belum tersedia",
      amountLabel:
        typeof initialConfig.stickyAmountLabel === "string"
          ? initialConfig.stickyAmountLabel
          : "Total review (IDR)",
      submitLabel:
        typeof initialConfig.stickySubmitLabel === "string"
          ? initialConfig.stickySubmitLabel
          : "Lanjut bayar",
    },
    contactDefaultHelp:
      typeof initialConfig.contactDefaultHelp === "string"
        ? initialConfig.contactDefaultHelp
        : "",
    contactDisclosureOpen: initialConfig.contactDisclosureOpen === true,
  };

  return {
    quantity: initialConfig.quantity ?? null,
    selectedPaymentMethod: config.defaultPaymentMethod,
    paymentOptionsState: config.paymentOptions,
    stickyDefaults: { ...config.stickyDefaults },
    contactDefaultHelp: config.contactDefaultHelp,
    canSubmit: config.canSubmit,
    formIsValid: false,
    quantityAnnouncement: "",
    contactDisclosureOpen: config.contactDisclosureOpen,
    stickyActionHeight: 0,
    mobileViewport: false,
    resizeObserver: null,
    resizeListener: null,
    focusFrame: null,

    get currentQuantity() {
      const quantity = safeInteger(this.quantity);
      return quantity !== null &&
        quantity >= config.minimumQuantity &&
        quantity <= config.maximumQuantity
        ? quantity
        : null;
    },

    get quote() {
      return quoteForQuantity(config, this.quantity);
    },

    get paymentOptions() {
      return this.paymentOptionsState;
    },

    get selectedPaymentOption() {
      return (
        this.paymentOptions.find(
          (option) => option.value === this.selectedPaymentMethod
        ) ?? null
      );
    },

    get contactRequired() {
      return this.selectedPaymentOption?.provider === "sakurupiah";
    },

    get contactRequirementText() {
      return this.contactRequired
        ? "WhatsApp wajib untuk metode pembayaran ini"
        : "Opsional";
    },

    get contactHelpText() {
      return this.contactRequired
        ? "Nomor WhatsApp diperlukan untuk metode pembayaran ini. Email dan kontak bantuan tetap bukan bukti kepemilikan pesanan."
        : this.contactDefaultHelp;
    },

    get selectedPaymentLabel() {
      return (
        this.selectedPaymentOption?.label ?? this.stickyDefaults.paymentLabel
      );
    },
    get selectedPaymentDescription() {
      return this.selectedPaymentOption?.description ?? "";
    },

    get selectedAmountLabel() {
      return (
        this.selectedPaymentOption?.amountLabel ??
        this.stickyDefaults.amountLabel
      );
    },

    get selectedAmountHelp() {
      return this.selectedPaymentOption?.amountHelp ?? "";
    },

    get selectedAmountValue() {
      return this.amountForKind(
        this.selectedPaymentOption?.amountKind ?? "idr"
      );
    },

    get stickyPaymentLabel() {
      return this.selectedPaymentLabel;
    },

    get stickyAmountLabel() {
      return this.selectedAmountLabel;
    },

    get stickyAmountValue() {
      return this.selectedAmountValue;
    },

    get stickySubmitLabel() {
      return (
        this.selectedPaymentOption?.submitLabel ??
        this.stickyDefaults.submitLabel
      );
    },

    get stickySubmitDisabled() {
      return (
        !this.canSubmit ||
        this.selectedPaymentOption === null ||
        !this.formIsValid
      );
    },

    get stickySubmitDescription() {
      return this.canSubmit ? null : "checkout-submit-disabled-help";
    },

    get currentSubtotalIdr() {
      return quantityEditable
        ? (this.quote?.idrSubtotal ?? null)
        : config.initialIdrSubtotal;
    },

    get currentSubtotalUsdt() {
      return quantityEditable
        ? (this.quote?.usdtSubtotal ?? null)
        : config.initialUsdtSubtotal;
    },

    get summaryItemCount() {
      return quantityEditable
        ? (this.currentQuantity ?? "—")
        : config.initialItemCount;
    },

    get stickyBottomPadding() {
      return this.mobileViewport && this.stickyActionHeight > 0
        ? `${this.stickyActionHeight}px`
        : null;
    },

    formatIdr,
    formatUsdt,

    amountForKind(kind) {
      return kind === "usdt"
        ? formatUsdt(this.currentSubtotalUsdt)
        : formatIdr(this.currentSubtotalIdr);
    },

    itemQuantityText(productId) {
      if (quantityEditable) {
        return this.currentQuantity === null
          ? "Jumlah —"
          : `Jumlah ${this.currentQuantity}`;
      }

      const item = config.items.find(
        (candidate) => candidate.productId === String(productId)
      );
      return item?.quantity === null
        ? "Jumlah —"
        : `Jumlah ${item?.quantity ?? "—"}`;
    },

    itemIdrUnitPrice(productId) {
      if (quantityEditable) {
        return formatIdr(this.quote?.idrUnitPrice ?? null);
      }

      const item = config.items.find(
        (candidate) => candidate.productId === String(productId)
      );
      return formatIdr(item?.idrUnitPrice ?? null);
    },

    itemUsdtUnitPrice(productId) {
      if (quantityEditable) {
        return formatUsdt(this.quote?.usdtUnitPrice ?? null);
      }

      const item = config.items.find(
        (candidate) => candidate.productId === String(productId)
      );
      return formatUsdt(item?.usdtUnitPrice ?? null);
    },

    itemHasUsdtPrice(productId) {
      return this.itemUsdtUnitPrice(productId) !== "—";
    },

    itemTierNote(productId) {
      const minimum = quantityEditable
        ? (this.quote?.appliedTierMinimum ?? null)
        : (config.items.find(
            (candidate) => candidate.productId === String(productId)
          )?.tierMinimum ?? null);
      return minimum === null
        ? ""
        : `Harga grosir diterapkan mulai ${minimum} unit.`;
    },

    itemHasTier(productId) {
      return this.itemTierNote(productId) !== "";
    },

    itemIdrSubtotal(productId) {
      if (quantityEditable) {
        return formatIdr(this.quote?.idrSubtotal ?? null);
      }

      const item = config.items.find(
        (candidate) => candidate.productId === String(productId)
      );
      return formatIdr(item?.idrSubtotal ?? null);
    },

    get orderSubtotalIdrText() {
      return formatIdr(this.currentSubtotalIdr);
    },

    get orderSubtotalUsdtText() {
      return formatUsdt(this.currentSubtotalUsdt);
    },

    stepQuantity(step) {
      const increment = safeInteger(step);
      if (
        !quantityEditable ||
        increment === null ||
        config.maximumQuantity === null ||
        config.maximumQuantity < config.minimumQuantity
      ) {
        return;
      }

      const rawQuantity = safeInteger(this.quantity);
      const current =
        rawQuantity === null
          ? config.minimumQuantity
          : Math.min(
              config.maximumQuantity,
              Math.max(config.minimumQuantity, rawQuantity)
            );
      this.quantity = Math.min(
        config.maximumQuantity,
        Math.max(config.minimumQuantity, current + increment)
      );
      this.announceQuantity();
      this.refreshFormValidity();
    },

    announceQuantity() {
      const quantity = this.currentQuantity;
      const quote = this.quote;
      if (quantity === null || quote === null) {
        this.quantityAnnouncement =
          quantity === null
            ? "Jumlah tidak valid; subtotal review belum tersedia."
            : `Jumlah ${quantity} unit; subtotal review belum tersedia.`;
        return;
      }

      this.quantityAnnouncement = `Jumlah ${quantity} unit; subtotal review ${formatIdr(quote.idrSubtotal)}.`;
    },

    refreshFormValidity() {
      const channel = this.$refs?.checkoutForm?.elements?.namedItem?.(
        "support_contact_channel"
      );
      const contact = this.$refs?.checkoutForm?.elements?.namedItem?.(
        "support_contact_value"
      );
      if (channel && contact) {
        channel.setCustomValidity(
          this.contactRequired && channel.value !== "whatsapp"
            ? "Pilih WhatsApp untuk metode pembayaran ini."
            : ""
        );
        contact.pattern = this.contactRequired
          ? "\\+?[0-9][0-9 .\\-]{7,19}"
          : "";
        contact.title = this.contactRequired
          ? "Masukkan nomor WhatsApp yang valid."
          : "";
      }
      const refresh = () => {
        this.formIsValid = Boolean(
          this.$refs?.checkoutForm?.matches?.(":valid")
        );
      };

      if (typeof this.$nextTick === "function") {
        this.$nextTick(refresh);
      } else {
        refresh();
      }
    },

    init() {
      const root = this.$root;
      const options = paymentOptionsFrom(root);
      if (options.length > 0) {
        this.paymentOptionsState = options;
      }

      const checkedPayment = root?.querySelector?.(
        'input[name="payment_method"]:checked'
      );
      const selectedOption = root
        ?.querySelector?.("[data-checkout-selected-option]")
        ?.getAttribute?.("data-payment-value");
      const selectedMethod = checkedPayment?.value ?? selectedOption;
      if (typeof selectedMethod === "string" && selectedMethod !== "") {
        this.selectedPaymentMethod = selectedMethod;
      }

      const stickyPaymentLabel = readText(
        root,
        "[data-checkout-sticky-payment]"
      );
      const stickyAmountLabel = readText(
        root,
        "[data-checkout-sticky-amount-label]"
      );
      const stickySubmitLabel = readText(root, "[data-checkout-sticky-submit]");
      this.stickyDefaults = {
        paymentLabel: stickyPaymentLabel || this.stickyDefaults.paymentLabel,
        amountLabel: stickyAmountLabel || this.stickyDefaults.amountLabel,
        submitLabel: stickySubmitLabel || this.stickyDefaults.submitLabel,
      };
      this.contactDefaultHelp =
        readContactDefaultHelp(root) || this.contactDefaultHelp;
      const contactDetails = root?.querySelector?.(
        "[data-checkout-contact-details]"
      );
      if (contactDetails) {
        this.contactDisclosureOpen = contactDetails.open;
      }

      const errorSummary = root?.querySelector?.(
        "[data-checkout-error-summary]"
      );
      if (
        errorSummary &&
        typeof window !== "undefined" &&
        typeof window.requestAnimationFrame === "function"
      ) {
        this.focusFrame = window.requestAnimationFrame(() =>
          errorSummary.focus?.({ preventScroll: true })
        );
      }

      this.$nextTick?.(() => this.observeStickyAction());
      this.refreshFormValidity();
    },

    updateViewport() {
      this.mobileViewport =
        typeof window !== "undefined" &&
        typeof window.matchMedia === "function" &&
        window.matchMedia("(max-width: 767px)").matches;
    },

    measureStickyAction() {
      const height =
        this.$refs?.stickyAction?.getBoundingClientRect?.().height ?? 0;
      this.stickyActionHeight =
        Number.isFinite(height) && height > 0 ? height : 0;
    },

    observeStickyAction() {
      const stickyAction = this.$refs?.stickyAction;
      if (!stickyAction) {
        return;
      }

      this.updateViewport();
      this.measureStickyAction();
      if (
        typeof window !== "undefined" &&
        typeof window.addEventListener === "function"
      ) {
        this.resizeListener = () => {
          this.updateViewport();
          this.measureStickyAction();
        };
        window.addEventListener("resize", this.resizeListener, {
          passive: true,
        });
      }

      if (typeof ResizeObserver !== "undefined") {
        this.resizeObserver = new ResizeObserver(() =>
          this.measureStickyAction()
        );
        this.resizeObserver.observe(stickyAction);
      }
    },

    destroy() {
      this.resizeObserver?.disconnect();
      this.resizeObserver = null;

      if (this.resizeListener && typeof window !== "undefined") {
        window.removeEventListener?.("resize", this.resizeListener);
        this.resizeListener = null;
      }

      if (this.focusFrame !== null && typeof window !== "undefined") {
        window.cancelAnimationFrame?.(this.focusFrame);
        this.focusFrame = null;
      }
    },
  };
}
