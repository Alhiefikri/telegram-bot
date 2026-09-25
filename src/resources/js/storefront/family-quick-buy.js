const FALLBACK_BASE_URL = "https://family-quick-buy.invalid/";

function normalizeStock(stock) {
  const amount = Number(stock);
  return Number.isFinite(amount) ? Math.max(0, Math.floor(amount)) : 0;
}

function normalizeQuantity(value, maximum) {
  if (maximum < 1) {
    return 1;
  }

  const amount = Number(value);
  const integer = Number.isFinite(amount) ? Math.floor(amount) : 1;

  return Math.max(1, Math.min(maximum, integer));
}

function checkoutUrlWithQuantity(href, quantity) {
  const isAbsolute = /^[a-z][a-z\d+.-]*:/i.test(href);
  const isProtocolRelative = href.startsWith("//");
  const url = new URL(href, FALLBACK_BASE_URL);
  url.searchParams.set("quantity", String(quantity));

  const path = `${url.pathname}${url.search}${url.hash}`;

  if (isProtocolRelative) {
    return `//${url.host}${path}`;
  }

  return isAbsolute ? url.toString() : path;
}

export default function familyQuickBuy(initialOptions) {
  const options = (Array.isArray(initialOptions) ? initialOptions : []).map(
    (option) => ({
      id: String(option.id ?? ""),
      name: String(option.name ?? ""),
      priceLabel: String(option.priceLabel ?? ""),
      stock: normalizeStock(option.stock),
      selected: Boolean(option.selected),
      productUrl: String(option.productUrl ?? ""),
      checkoutUrl: String(option.checkoutUrl ?? ""),
    })
  );
  const initialOption =
    options.find((option) => option.selected && option.stock > 0) ??
    options.find((option) => option.stock > 0) ??
    options.find((option) => option.selected) ??
    options[0] ??
    null;

  return {
    options,
    selectedProductId: initialOption?.id ?? null,
    quantity: 1,
    quantityMinimum: 1,
    announcement: "",

    get selectedOption() {
      return (
        this.options.find((option) => option.id === this.selectedProductId) ??
        null
      );
    },

    get isAvailable() {
      return (this.selectedOption?.stock ?? 0) > 0;
    },

    get quantityMaximum() {
      return this.isAvailable ? Math.min(20, this.selectedOption.stock) : 0;
    },

    get checkoutHref() {
      const option = this.selectedOption;

      if (!option) {
        return "#";
      }

      if (!this.isAvailable) {
        return option.productUrl || "#";
      }

      return checkoutUrlWithQuantity(option.checkoutUrl, this.quantity);
    },

    get checkoutLabel() {
      return this.isAvailable ? "Beli sekarang" : "Lihat detail opsi";
    },

    get checkoutAriaLabel() {
      const name = this.selectedOption?.name || "opsi";
      return this.isAvailable
        ? `Beli ${name} sekarang`
        : `Lihat detail ${name}`;
    },

    isSelected(productId) {
      return this.selectedProductId === String(productId);
    },

    selectOption(productId, announce = true) {
      const option = this.options.find(
        (candidate) => candidate.id === String(productId)
      );

      if (!option) {
        return;
      }

      const changed = option.id !== this.selectedProductId;
      this.selectedProductId = option.id;
      this.quantity = normalizeQuantity(this.quantity, this.quantityMaximum);

      if (changed && announce) {
        this.announcement = `${option.name || "Opsi"} dipilih`;
      }
    },

    setQuantity(value) {
      this.quantity = normalizeQuantity(value, this.quantityMaximum);
    },

    adjustQuantity(step) {
      const amount = Number(step);

      if (!this.isAvailable || !Number.isFinite(amount)) {
        return;
      }

      this.setQuantity(this.quantity + Math.trunc(amount));
    },

    handleOptionKeydown(event, productId, refs) {
      const key = event.key === "Spacebar" ? " " : event.key;
      const currentIndex = this.options.findIndex(
        (option) => option.id === String(productId)
      );

      if (currentIndex < 0 || this.options.length === 0) {
        return;
      }

      if (key === " " || key === "Enter") {
        event.preventDefault();
        this.selectOption(productId);
        return;
      }

      let nextIndex;

      if (key === "ArrowRight") {
        nextIndex = (currentIndex + 1) % this.options.length;
      } else if (key === "ArrowLeft") {
        nextIndex =
          (currentIndex - 1 + this.options.length) % this.options.length;
      } else if (key === "Home") {
        nextIndex = 0;
      } else if (key === "End") {
        nextIndex = this.options.length - 1;
      } else {
        return;
      }

      event.preventDefault();
      const nextOption = this.options[nextIndex];
      this.selectOption(nextOption.id);
      refs?.[`family-option-button-${nextOption.id}`]?.focus?.();
    },
  };
}
