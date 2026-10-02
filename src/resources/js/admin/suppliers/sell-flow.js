const MIN_SEARCH_LENGTH = 2;
const SEARCH_DEBOUNCE_MS = 250;
const DEFAULT_MARKUP_PERCENT = 20;

export const suggestPrice = (
  costIdr,
  markupPercent = DEFAULT_MARKUP_PERCENT
) => {
  const cost = Number(costIdr);
  if (!Number.isFinite(cost) || cost <= 0) {
    return "";
  }

  return String(Math.ceil((cost * (1 + markupPercent / 100)) / 1000) * 1000);
};

const defaultFetch = (...args) => globalThis.fetch(...args);

export default function supplierSellFlow({
  searchUrl,
  fetchRequest = defaultFetch,
} = {}) {
  return {
    open: false,
    mode: "new",
    item: null,
    name: "",
    price: "",
    description: "",
    copySearch: "",
    copySearching: false,
    copyResults: [],
    copiedFrom: null,
    copyTimer: null,
    copyRequestId: 0,
    search: "",
    searching: false,
    results: [],
    selected: null,
    equivalentConfirmed: false,
    submitting: false,
    searchTimer: null,
    searchRequestId: 0,
    modalOpener: null,

    openFor(detail) {
      this.modalOpener = globalThis.document?.activeElement ?? null;
      this.item = detail ?? null;
      this.mode = "new";
      this.name = detail?.name ?? "";
      const suggestedPrice =
        detail?.suggested_price ?? suggestPrice(detail?.cost_idr);
      this.price =
        suggestedPrice === null || suggestedPrice === undefined
          ? ""
          : String(suggestedPrice);
      this.description = "";
      this.copySearch = "";
      this.copySearching = false;
      this.copyResults = [];
      this.copiedFrom = null;
      this.copyRequestId += 1;
      this.search = "";
      this.searching = false;
      this.results = [];
      this.selected = null;
      this.equivalentConfirmed = false;
      this.submitting = false;
      this.open = true;
      this.$nextTick?.(() => this.$refs?.sellName?.focus?.());
    },

    close() {
      if (this.submitting) {
        return;
      }

      this.open = false;
      this.results = [];
      this.searchRequestId += 1;
      const opener = this.modalOpener;
      this.modalOpener = null;
      this.$nextTick?.(() => {
        if (opener?.isConnected) {
          opener.focus();
        }
      });
    },

    queueSearch() {
      clearTimeout(this.searchTimer);
      const query = String(this.search ?? "").trim();
      if (query.length < MIN_SEARCH_LENGTH) {
        this.results = [];
        return;
      }

      this.searchTimer = setTimeout(() => this.runSearch(), SEARCH_DEBOUNCE_MS);
    },

    async runSearch() {
      const query = String(this.search ?? "").trim();
      if (query.length < MIN_SEARCH_LENGTH) {
        this.results = [];
        return;
      }

      const requestId = ++this.searchRequestId;
      this.searching = true;

      try {
        const url = new URL(
          searchUrl,
          globalThis.location?.origin ?? "http://localhost"
        );
        url.searchParams.set("q", query);
        const response = await fetchRequest(url.toString(), {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        const body = await response.json().catch(() => ({}));

        if (requestId !== this.searchRequestId) {
          return;
        }

        this.results =
          response.ok && Array.isArray(body.products) ? body.products : [];
        if (!response.ok) {
          this.results = [];
        }
      } catch {
        if (requestId === this.searchRequestId) {
          this.results = [];
        }
      } finally {
        if (requestId === this.searchRequestId) {
          this.searching = false;
        }
      }
    },

    queueCopySearch() {
      clearTimeout(this.copyTimer);
      const query = String(this.copySearch ?? "").trim();
      if (query.length < MIN_SEARCH_LENGTH) {
        this.copyResults = [];
        return;
      }

      this.copyTimer = setTimeout(
        () => this.runCopySearch(),
        SEARCH_DEBOUNCE_MS
      );
    },

    async runCopySearch() {
      const query = String(this.copySearch ?? "").trim();
      if (query.length < MIN_SEARCH_LENGTH) {
        this.copyResults = [];
        return;
      }

      const requestId = ++this.copyRequestId;
      this.copySearching = true;

      try {
        const url = new URL(
          searchUrl,
          globalThis.location?.origin ?? "http://localhost"
        );
        url.searchParams.set("q", query);
        url.searchParams.set("include", "description");
        const response = await fetchRequest(url.toString(), {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        const body = await response.json().catch(() => ({}));

        if (requestId !== this.copyRequestId) {
          return;
        }

        this.copyResults =
          response.ok && Array.isArray(body.products) ? body.products : [];
      } catch {
        if (requestId === this.copyRequestId) {
          this.copyResults = [];
        }
      } finally {
        if (requestId === this.copyRequestId) {
          this.copySearching = false;
        }
      }
    },

    applyCopy(product) {
      this.description = String(product?.description ?? "");
      this.copiedFrom = product?.name ?? null;
      this.copySearch = "";
      this.copyResults = [];
      this.copySearching = false;
    },

    selectProduct(product) {
      this.selected = product ?? null;
      this.search = "";
      this.results = [];
      this.equivalentConfirmed = false;
    },

    clearSelected() {
      this.selected = null;
    },

    canSubmitLink() {
      return (
        this.selected !== null && this.equivalentConfirmed && !this.submitting
      );
    },

    markSubmitting() {
      this.submitting = true;
    },

    focusables(container) {
      if (!container) {
        return [];
      }

      return Array.from(
        container.querySelectorAll(
          'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
      ).filter(
        (element) =>
          typeof element.getClientRects !== "function" ||
          element.getClientRects().length > 0
      );
    },

    trapModalFocus(event) {
      const focusable = this.focusables(event.currentTarget);
      const first = focusable[0];
      const last = focusable.at(-1);

      if (!first || !last) {
        event.preventDefault();
        event.currentTarget.focus();
        return;
      }

      if (event.shiftKey && globalThis.document?.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (
        !event.shiftKey &&
        globalThis.document?.activeElement === last
      ) {
        event.preventDefault();
        first.focus();
      }
    },

    keepModalFocus(event, modalId) {
      const dialog = globalThis.document?.getElementById?.(modalId);
      if (
        !dialog ||
        typeof dialog.getClientRects !== "function" ||
        dialog.getClientRects().length === 0 ||
        dialog.contains(event.target)
      ) {
        return;
      }

      (this.focusables(dialog)[0] ?? dialog).focus();
    },
  };
}
