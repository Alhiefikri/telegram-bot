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
  return Number.isSafeInteger(amount)
    ? idrFormatter.format(amount)
    : "Belum tersedia";
}

function formatUsdt(microAmount) {
  if (!Number.isSafeInteger(microAmount)) {
    return "Belum tersedia";
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
  };

  return {
    quantity: initialConfig.quantity ?? null,
    submitting: false,
    quantityAnnouncement: "",

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

    get subtotalIdr() {
      return formatIdr(this.quote?.idrSubtotal);
    },

    get subtotalUsdt() {
      return formatUsdt(this.quote?.usdtSubtotal);
    },

    get tierNote() {
      const minimum = this.quote?.appliedTierMinimum;
      return minimum ? `Harga grosir diterapkan mulai ${minimum} unit.` : "";
    },

    syncContact() {
      const form = this.$refs.checkoutForm;
      if (!form) {
        return;
      }
      const method = form.querySelector(
        'input[name="payment_method"]:is(:checked, [type="hidden"])'
      );
      const required = method?.dataset.paymentProvider === "sakurupiah";
      const channel = form.elements.namedItem("support_contact_channel");
      const contact = form.elements.namedItem("support_contact_value");
      if (!channel || !contact) {
        return;
      }

      if (required) {
        this.$refs.contactDetails.open = true;
      }
      channel.required = contact.required = required;
      channel.setCustomValidity(
        required && channel.value !== "whatsapp"
          ? "Pilih WhatsApp untuk metode pembayaran ini."
          : ""
      );
      if (required) {
        contact.pattern = "\\+?[0-9][0-9 .\\-]{7,19}";
        contact.title = "Masukkan nomor WhatsApp yang valid.";
      } else {
        contact.removeAttribute("pattern");
        contact.removeAttribute("title");
      }
    },

    openInvalidDetails(event) {
      const details = event.target.closest("details");
      if (details) {
        details.open = true;
      }
    },

    submit(event) {
      if (
        this.submitting ||
        !event.currentTarget.querySelector("[data-checkout-submit]:enabled")
      ) {
        event.preventDefault();
        return;
      }
      this.submitting = true;
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
    },

    announceQuantity() {
      const quantity = this.currentQuantity;
      const quote = this.quote;
      if (quantity === null || quote === null) {
        this.quantityAnnouncement =
          "Subtotal produk belum tersedia. Periksa jumlah.";
        return;
      }

      const isUsdt = this.$refs?.checkoutForm?.querySelector(
        'input[name="payment_method"]:is(:checked, [type="hidden"]):not([value="qris"])'
      );
      const subtotal = isUsdt
        ? formatUsdt(quote.usdtSubtotal)
        : formatIdr(quote.idrSubtotal);
      this.quantityAnnouncement = `Jumlah ${quantity} unit. Subtotal produk ${subtotal}.`;
    },

    init() {
      this.$nextTick(() => {
        this.syncContact();
        this.$root.querySelector("[data-checkout-error-summary]")?.focus();
      });
    },
  };
}
