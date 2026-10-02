const PREVIEW_DEBOUNCE_MS = 300;
const MAX_BASIS_POINTS = 100000;

const defaultFetch = (...args) => globalThis.fetch(...args);

export const percentToBasisPoints = (percent) => {
  const value = Number(percent);
  if (!Number.isFinite(value) || value <= 0) {
    return 0;
  }

  return Math.min(MAX_BASIS_POINTS, Math.round(value * 100));
};

export const basisPointsToPercent = (basisPoints) => {
  const value = Number(basisPoints);
  if (!Number.isFinite(value) || value <= 0) {
    return 0;
  }

  return Math.round(value) / 100;
};

export const formatIdr = (amount) => {
  if (amount === null || amount === undefined || amount === "") {
    return "—";
  }

  const value = Number(amount);
  if (!Number.isFinite(value)) {
    return "—";
  }

  return `Rp${new Intl.NumberFormat("id-ID").format(Math.round(value))}`;
};

export const formatPercent = (basisPoints) => {
  if (basisPoints === null || basisPoints === undefined || basisPoints === "") {
    return "—";
  }

  const value = Number(basisPoints);
  if (!Number.isFinite(value)) {
    return "—";
  }

  const percent = Math.round(value) / 100;
  const label = Number.isInteger(percent)
    ? String(percent)
    : percent.toFixed(1).replace(".", ",");

  return `${label}%`;
};

export default function supplierPricingCalculator({
  previewUrl,
  csrfToken = "",
  initial = {},
  fetchRequest = defaultFetch,
} = {}) {
  return {
    mode: initial.mode ?? "manual",
    percent: basisPointsToPercent(initial.markup_basis_points),
    profitIdr: Number(initial.profit_idr ?? 0),
    minimumProfitIdr: Number(initial.minimum_profit_idr ?? 0),
    bufferIdr: Number(initial.buffer_idr ?? 0),
    roundingIdr: Number(initial.rounding_idr ?? 1),
    catalogItemId: initial.catalog_item_id ?? "",
    policyVersion: Number(initial.version ?? 0),
    currentRetailIdr: Number(initial.current_retail_idr ?? 0),
    preview: initial.preview ?? null,
    previewLoading: false,
    previewError: "",
    previewTimer: null,
    previewRequestId: 0,

    get basisPoints() {
      return percentToBasisPoints(this.percent);
    },

    get isManual() {
      return this.mode === "manual";
    },

    get hasCatalogItem() {
      return Number(this.catalogItemId) > 0;
    },

    formatIdr,
    formatPercent,

    changed() {
      this.schedulePreview();
    },

    schedulePreview() {
      clearTimeout(this.previewTimer);
      this.previewError = "";

      if (this.isManual) {
        this.preview = null;
        return;
      }

      if (!this.hasCatalogItem) {
        this.preview = null;
        this.previewError = "Pilih SKU supplier dulu untuk menghitung harga.";
        return;
      }

      this.previewTimer = setTimeout(
        () => this.loadPreview(),
        PREVIEW_DEBOUNCE_MS
      );
    },

    async loadPreview() {
      if (this.isManual || !this.hasCatalogItem) {
        return;
      }

      const requestId = ++this.previewRequestId;
      this.previewLoading = true;

      try {
        const response = await fetchRequest(previewUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrfToken,
          },
          body: JSON.stringify({
            version: this.policyVersion,
            mode: this.mode,
            markup_basis_points: this.basisPoints,
            profit_idr: Number(this.profitIdr) || 0,
            minimum_profit_idr: Number(this.minimumProfitIdr) || 0,
            buffer_idr: Number(this.bufferIdr) || 0,
            rounding_idr: Number(this.roundingIdr) || 1,
            catalog_item_id: Number(this.catalogItemId),
          }),
        });
        const body = await response.json().catch(() => ({}));

        if (requestId !== this.previewRequestId) {
          return;
        }

        if (!response.ok) {
          this.preview = null;
          this.previewError =
            body?.message ?? "Perhitungan harga tidak dapat ditampilkan.";
          return;
        }

        this.preview = body;
      } catch {
        if (requestId === this.previewRequestId) {
          this.preview = null;
          this.previewError =
            "Koneksi gagal. Angka di bawah bisa jadi belum terbaru.";
        }
      } finally {
        if (requestId === this.previewRequestId) {
          this.previewLoading = false;
        }
      }
    },
  };
}
