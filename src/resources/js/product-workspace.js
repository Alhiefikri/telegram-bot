import adminDashboard from "./admin-dashboard.js";

export const sections = ["detail", "stock", "supplier", "price", "appearance"];
const detailFields = [
  "name",
  "category_id",
  "description",
  "description_ms",
  "description_en",
  "warranty_policy",
  "warranty_policy_ms",
  "warranty_policy_en",
  "delivery_note",
  "usage_guide_title",
  "usage_guide_url",
];
const appearanceFields = ["icon", "is_active", "featured_rank"];
const policyFields = [
  "mode",
  "profit_idr",
  "minimum_profit_idr",
  "rounding_idr",
];
const clone = (value) => JSON.parse(JSON.stringify(value));
const pick = (object, fields) =>
  Object.fromEntries(fields.map((field) => [field, object?.[field] ?? ""]));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const csrf = () =>
  globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? "";

export function percentToBasisPoints(value) {
  const text = String(value).trim().replace(",", ".");
  if (!/^\d+(?:\.\d{1,2})?$/.test(text))
    throw new Error("Isi persentase 0 sampai 1000, maksimal 2 angka desimal.");
  const [whole, decimal = ""] = text.split(".");
  const points = Number(whole) * 100 + Number(decimal.padEnd(2, "0"));
  if (!Number.isSafeInteger(points) || points > 100000)
    throw new Error("Persentase maksimal 1000%.");
  return points;
}

export function basisPointsToPercent(value) {
  if (
    !Number.isInteger(Number(value)) ||
    Number(value) < 0 ||
    Number(value) > 100000
  )
    throw new Error("Basis poin tidak valid.");
  const points = Number(value);
  return `${Math.floor(points / 100)}${
    points % 100
      ? `.${String(points % 100)
          .padStart(2, "0")
          .replace(/0$/, "")}`
      : ""
  }`;
}

function formsFor(workspace) {
  const detail = pick(workspace.product, detailFields);
  return {
    detail,
    price: {
      ...pick(workspace.product, ["price_amount", "usdt_price_amount"]),
      quantity_price_tiers: clone(
        workspace.product?.quantity_price_tiers ?? []
      ),
    },
    appearance: pick(workspace.product, appearanceFields),
    policy: {
      ...pick(workspace.policy, policyFields),
      markup_percent: basisPointsToPercent(
        workspace.policy?.markup_basis_points ?? 0
      ),
    },
    family: pick(workspace.product, [
      "family_id",
      "family_sort_order",
      "family_option_label",
    ]),
  };
}

export default function productWorkspace(
  initialPayload,
  { fetchRequest = (...args) => fetch(...args) } = {}
) {
  const initial = formsFor(initialPayload);
  return {
    workspace: clone(initialPayload),
    draft: clone(initial),
    saved: clone(initial),
    openSection: sections.includes(initialPayload.initial_section)
      ? initialPayload.initial_section
      : null,
    errors: {},
    feedback: {},
    outcomes: {},
    saving: {},
    requests: {},
    files: {},
    filePreviews: {},
    externalDirty: false,
    refreshSequence: 0,
    destroyed: false,
    refreshFlight: null,
    supplierDialogEpoch: 0,
    catalogSequence: 0,
    refreshPending: false,
    contentLocale: "id",
    catalog: [],
    catalogQuery: "",
    catalogSupplier: "",
    catalogResultQuery: "",
    catalogResultSupplier: "",
    catalogPage: 1,
    catalogLastPage: 1,
    catalogLoading: false,
    catalogError: "",
    catalogSelection: "",
    equivalentConfirmed: false,
    supplierDialogOpen: false,
    dialogOrigin: null,
    preview: initialPayload.price_preview ?? null,
    previewLoading: false,
    previewCatalogId: initialPayload.preview_catalog_item_id ?? "",
    previewSequence: 0,
    init() {
      const requested = new URL(
        globalThis.location?.href ?? "http://localhost"
      ).searchParams.get("section");
      if (sections.includes(requested)) this.openSection = requested;
      this.beforeUnload = (event) => {
        if (!this.hasUnsaved()) return;
        event.preventDefault();
        event.returnValue = "";
      };
      globalThis.window?.addEventListener("beforeunload", this.beforeUnload);
      this.$watch?.("draft.policy", () => this.invalidatePreview());
      this.$watch?.("previewCatalogId", () => this.invalidatePreview());
      this.$watch?.("catalogQuery", () => this.invalidateCatalog());
      this.$watch?.("catalogSupplier", () => this.invalidateCatalog());
    },
    destroy() {
      this.destroyed = true;
      ++this.refreshSequence;
      ++this.catalogSequence;
      ++this.previewSequence;
      this.refreshPending = false;
      globalThis.window?.removeEventListener("beforeunload", this.beforeUnload);
      Object.values(this.filePreviews).forEach((url) =>
        URL.revokeObjectURL(url)
      );
      this.files = {};
      this.filePreviews = {};
      this.catalog = [];
    },
    localeKeydown(event, locale) {
      const locales = ["id", "ms", "en"],
        index = locales.indexOf(locale);
      const next =
        event.key === "ArrowRight"
          ? locales[(index + 1) % 3]
          : event.key === "ArrowLeft"
            ? locales[(index + 2) % 3]
            : event.key === "Home"
              ? "id"
              : event.key === "End"
                ? "en"
                : null;
      if (!next) return;
      event.preventDefault();
      this.contentLocale = next;
      globalThis.document?.getElementById(`workspace-locale-${next}`)?.focus();
    },
    toggle(section) {
      if (sections.includes(section))
        this.openSection = this.openSection === section ? null : section;
    },
    mutationBusy() {
      if (this.destroyed) return true;
      return Object.values(this.saving).some(Boolean);
    },
    dirty(form) {
      return (
        !same(this.draft[form], this.saved[form]) ||
        (form === "appearance" && Object.keys(this.files).length > 0)
      );
    },
    sectionDirty(section) {
      return section === "detail"
        ? this.dirty("detail") || this.dirty("family")
        : section === "price"
          ? this.dirty("price") || this.dirty("policy")
          : section === "stock"
            ? this.externalDirty
            : section === "supplier"
              ? false
              : this.dirty(section);
    },
    hasUnsaved() {
      return (
        Object.keys(this.draft).some((key) => this.dirty(key)) ||
        this.externalDirty ||
        this.equivalentConfirmed ||
        Boolean(this.catalogSelection)
      );
    },
    error(form, field) {
      const value = this.errors[form]?.[field];
      return Array.isArray(value) ? value[0] : (value ?? "");
    },
    messages(form) {
      return Object.values(this.errors[form] ?? {}).flat();
    },
    money(value) {
      return value === null || value === undefined
        ? "Belum tersedia"
        : new Intl.NumberFormat("id-ID", {
            style: "currency",
            currency: "IDR",
            maximumFractionDigits: 0,
          }).format(value);
    },
    supplierWarning() {
      const active = (this.workspace.sources ?? []).filter(
        (source) => source.is_active
      );
      if (active.some((source) => source.supplier.is_fresh === false))
        return "Data supplier kedaluwarsa. Sinkronkan sebelum mengandalkan kapasitasnya.";
      if (active.some((source) => !source.supplier.is_active))
        return "Ada koneksi supplier nonaktif. Periksa sumber pembelian.";
      return "";
    },
    summary(section) {
      const product = this.workspace.product;
      if (section === "detail")
        return `${product.name} · ${product.family_name || "Tanpa family"}`;
      if (section === "stock")
        return `${this.workspace.stock?.summary?.available ?? 0} tersedia · ${this.workspace.stock?.summary?.reserved ?? 0} dipesan · ${this.workspace.stock?.summary?.paid ?? 0} paid`;
      if (section === "supplier")
        return `${this.workspace.sources?.filter((source) => source.is_active).length ?? 0} sumber aktif · urutan utama dan cadangan`;
      if (section === "price")
        return `${this.money(product.price_amount)} · ${this.workspace.policy?.mode === "manual" ? "Manual" : "Otomatis"}`;
      return `${product.is_active ? "Aktif di katalog" : "Tidak aktif di katalog"} · ${product.image_path ? "Gambar toko diatur" : "Memakai gambar default"}`;
    },
    mergeWorkspace(payload, acceptedForm = null, submitted = null) {
      if (this.destroyed) return;
      this.invalidatePreview();
      const nextForms = formsFor(payload);
      for (const form of Object.keys(nextForms)) {
        if (form === acceptedForm) {
          this.saved[form] = clone(nextForms[form]);
          if (same(this.draft[form], submitted))
            this.draft[form] = clone(nextForms[form]);
        } else if (!this.dirty(form)) {
          this.saved[form] = clone(nextForms[form]);
          this.draft[form] = clone(nextForms[form]);
        }
      }
      // Dirty forms retain their original conflict token until explicitly saved or reloaded.
      const versions = { ...payload.versions };
      for (const form of ["detail", "price", "appearance", "family"]) {
        if (form !== acceptedForm && this.dirty(form))
          versions[form] = this.workspace.versions?.[form];
      }
      if (acceptedForm !== "policy" && this.dirty("policy"))
        payload = {
          ...payload,
          policy: { ...payload.policy, version: this.workspace.policy.version },
        };
      this.workspace = { ...payload, versions };
      this.$dispatch?.("workspace-data-updated", {
        stock_payload: { ...payload.edit_payload, ...payload.stock_payload },
      });
    },
    async stockBusyChanged(busy) {
      if (this.destroyed) return;
      this.saving.stock = busy;
      if (busy) ++this.refreshSequence;
      else await this.drainRefresh();
    },
    async acceptStockSaved(body) {
      if (this.destroyed) return;
      this.feedback.stock = body.message || "Stok tersimpan.";
      if (
        body.workspace &&
        !Object.entries(this.saving).some(
          ([key, busy]) => key !== "stock" && busy
        )
      ) {
        ++this.refreshSequence;
        this.refreshPending = false;
        this.mergeWorkspace(body.workspace);
      } else await this.refresh();
    },
    async drainRefresh() {
      if (this.destroyed || this.mutationBusy() || !this.refreshPending) return;
      this.refreshPending = false;
      await this.refresh();
    },
    async request(url, { method = "POST", body } = {}) {
      const multipart =
        typeof FormData !== "undefined" && body instanceof FormData;
      const response = await fetchRequest(url, {
        method,
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "X-CSRF-TOKEN": csrf(),
          ...(multipart ? {} : { "Content-Type": "application/json" }),
        },
        body:
          body === undefined
            ? undefined
            : multipart
              ? body
              : JSON.stringify(body),
      });
      const payload = await response.json().catch(() => ({}));
      if (response.redirected || !response.ok) {
        const failure = new Error(
          response.status === 409
            ? "Data berubah di tempat lain. Muat data terbaru sebelum menyimpan ulang; draf tetap ada."
            : payload.message || "Perubahan belum tersimpan. Coba lagi."
        );
        failure.errors = payload.errors ?? {};
        throw failure;
      }
      return payload;
    },
    async save(form) {
      if (this.mutationBusy()) return false;
      ++this.refreshSequence;
      const submitted = clone(this.draft[form]);
      let body = clone(submitted),
        url = this.workspace.urls.update;
      this.errors[form] = {};
      this.feedback[form] = "";
      this.outcomes[form] = false;
      if (form === "policy") {
        if (["percent", "hybrid"].includes(body.mode)) {
          try {
            body.markup_basis_points = percentToBasisPoints(
              body.markup_percent
            );
          } catch (error) {
            this.errors[form] = { markup_percent: [error.message] };
            this.openSection = "price";
            return false;
          }
        } else
          body.markup_basis_points =
            this.workspace.policy.markup_basis_points ?? 0;
        if (!["fixed", "hybrid"].includes(body.mode))
          body.profit_idr = this.workspace.policy.profit_idr ?? 0;
        delete body.markup_percent;
        body.version = this.workspace.policy.version;
        url = this.workspace.urls.price;
      } else if (form === "family") {
        url = this.workspace.urls.family_update;
        body.workspace_version = this.workspace.versions.family;
        body._method = "PATCH";
        body.product_family_id = body.family_id;
        delete body.family_id;
      } else {
        body.workspace_section = form;
        body.workspace_version = this.workspace.versions[form];
        body._method = "PATCH";
        if (form === "price") body.quantity_price_tiers_present = 1;
        if (form === "appearance") {
          const data = new FormData();
          for (const [key, value] of Object.entries(body))
            data.append(
              key,
              typeof value === "boolean" ? (value ? "1" : "0") : (value ?? "")
            );
          for (const [key, value] of Object.entries(this.files))
            data.append(key, value);
          body = data;
        }
      }
      const requestId = (this.requests[form] ?? 0) + 1;
      this.requests[form] = requestId;
      this.saving[form] = true;
      const sentFiles = { ...this.files };
      try {
        const result = await this.request(url, { body });
        if (this.destroyed || this.requests[form] !== requestId) return false;
        if (!result.workspace)
          throw new Error(
            "Respons penyimpanan tidak lengkap. Draf tetap ada; muat ulang data untuk memeriksa hasil."
          );
        this.mergeWorkspace(result.workspace, form, submitted);
        if (form === "appearance")
          for (const [key, file] of Object.entries(sentFiles)) {
            if (this.files[key] !== file) continue;
            delete this.files[key];
            URL.revokeObjectURL(this.filePreviews[key]);
            delete this.filePreviews[key];
            const input = this.$root?.querySelector(`[name="${key}"]`);
            if (input) input.value = "";
          }
        this.outcomes[form] = true;
        this.feedback[form] = result.message || "Perubahan tersimpan.";
        return true;
      } catch (error) {
        if (this.destroyed || this.requests[form] !== requestId) return false;
        this.errors[form] = error.errors ?? {};
        if (this.errors[form].markup_basis_points)
          this.errors[form].markup_percent =
            this.errors[form].markup_basis_points;
        const localizedError = Object.keys(this.errors[form]).find((field) =>
          /^(description|warranty_policy)(_(ms|en))?$/.test(field)
        );
        if (form === "detail" && localizedError)
          this.contentLocale = localizedError.endsWith("_en")
            ? "en"
            : localizedError.endsWith("_ms")
              ? "ms"
              : "id";
        this.feedback[form] = error.message || "Koneksi gagal. Draf tetap ada.";
        this.openSection = ["family"].includes(form)
          ? "detail"
          : form === "policy"
            ? "price"
            : form;
        this.$nextTick?.(() =>
          this.$root
            ?.querySelector(
              `[data-workspace-form="${form}"] [aria-invalid="true"]`
            )
            ?.focus()
        );
        return false;
      } finally {
        if (!this.destroyed && this.requests[form] === requestId) {
          this.saving[form] = false;
          await this.drainRefresh();
        }
      }
    },
    async refresh() {
      if (this.destroyed) return;
      if (this.mutationBusy()) {
        this.refreshPending = true;
        return;
      }
      if (this.refreshFlight?.id === this.refreshSequence)
        return this.refreshFlight.promise;
      const id = ++this.refreshSequence;
      const promise = (async () => {
        try {
          const result = await this.request(this.workspace.urls.workspace, {
            method: "GET",
          });
          if (!this.destroyed && id === this.refreshSequence)
            this.mergeWorkspace(result.workspace ?? result);
        } catch (error) {
          if (!this.destroyed && id === this.refreshSequence)
            this.feedback.stock = error.message;
        } finally {
          if (this.refreshFlight?.id === id) this.refreshFlight = null;
        }
      })();
      this.refreshFlight = { id, promise };
      return promise;
    },
    async reloadForm(form) {
      if (
        this.mutationBusy() ||
        !globalThis.confirm(
          "Muat ulang bagian ini? Hanya draf bagian ini yang diganti; draf bagian lain tetap ada."
        )
      )
        return false;
      const submitted = clone(this.draft[form]),
        sentFiles = { ...this.files };
      this.saving[form] = true;
      ++this.refreshSequence;
      try {
        const result = await this.request(this.workspace.urls.workspace, {
          method: "GET",
        });
        if (this.destroyed) return false;
        this.mergeWorkspace(result.workspace ?? result, form, submitted);
        if (form === "appearance")
          for (const [key, file] of Object.entries(sentFiles)) {
            if (this.files[key] !== file) continue;
            delete this.files[key];
            URL.revokeObjectURL(this.filePreviews[key]);
            delete this.filePreviews[key];
            const input = this.$root?.querySelector(`[name="${key}"]`);
            if (input) input.value = "";
          }
        this.errors[form] = {};
        this.feedback[form] = "Data terbaru bagian ini dimuat.";
        return true;
      } catch (error) {
        if (this.destroyed) return false;
        this.feedback[form] = error.message;
        return false;
      } finally {
        if (!this.destroyed) {
          this.saving[form] = false;
          await this.drainRefresh();
        }
      }
    },
    selectFile(field, event) {
      const file = event.target.files?.[0];
      if (this.filePreviews[field])
        URL.revokeObjectURL(this.filePreviews[field]);
      delete this.files[field];
      delete this.filePreviews[field];
      this.errors.appearance = {};
      if (!file) return;
      if (
        !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
        file.size > 5 * 1024 * 1024
      ) {
        this.errors.appearance = {
          [field]: ["Pilih JPG, PNG, atau WebP maksimal 5 MB."],
        };
        event.target.value = "";
        return;
      }
      this.files[field] = file;
      this.filePreviews[field] = URL.createObjectURL(file);
    },
    addTier() {
      if (this.draft.price.quantity_price_tiers.length < 5)
        this.draft.price.quantity_price_tiers.push({
          minimum_quantity: "",
          price_amount: "",
          usdt_price_amount: "",
        });
    },
    async sourceAction(source, action, direction = null) {
      if (this.saving.supplier) return;
      if (
        action === "map" &&
        !globalThis.confirm(
          "Paket, durasi, dan garansi sumber ini sesuai produk toko?"
        )
      )
        return;
      if (
        action === "detach" &&
        !globalThis.confirm(
          "Lepas mapping yang belum digunakan? Mapping dengan riwayat tidak dapat dilepas; nonaktifkan sumber tersebut."
        )
      )
        return;
      await this.mutateSupplier(
        source.urls[action],
        {
          product_id: this.workspace.product.id,
          source_id: source.id,
          version: source.version,
          supplier_version: source.supplier_version,
          priority: source.priority,
          is_active: source.is_active ? 0 : 1,
          equivalent_confirmed: 1,
          ...(direction ? { direction } : {}),
        },
        action === "detach" ? "DELETE" : "POST"
      );
    },
    async mutateSupplier(url, body, method = "POST") {
      if (this.mutationBusy()) return false;
      ++this.refreshSequence;
      this.saving.supplier = true;
      this.feedback.supplier = "";
      this.errors.supplier = {};
      this.outcomes.supplier = false;
      try {
        const result = await this.request(url, { method, body });
        if (this.destroyed) return false;
        if (result.workspace) this.mergeWorkspace(result.workspace);
        else await this.refresh();
        this.outcomes.supplier = true;
        this.feedback.supplier = result.message || "Sumber supplier tersimpan.";
        return true;
      } catch (error) {
        if (this.destroyed) return false;
        this.feedback.supplier = error.message;
        this.errors.supplier = error.errors ?? {};
        return false;
      } finally {
        if (!this.destroyed) {
          this.saving.supplier = false;
          await this.drainRefresh();
        }
      }
    },
    openSupplierDialog() {
      if (this.destroyed || this.supplierDialogOpen) return;
      ++this.supplierDialogEpoch;
      this.invalidateCatalog();
      this.dialogOrigin = globalThis.document?.activeElement;
      this.supplierDialogOpen = true;
      this.$refs?.supplierDialog?.showModal();
      this.$refs?.catalogSearch?.focus();
      this.searchCatalog();
    },
    closeSupplierDialog() {
      ++this.supplierDialogEpoch;
      this.invalidateCatalog();
      this.$refs?.supplierDialog?.close();
      this.supplierDialogOpen = false;
      this.catalogSelection = "";
      this.equivalentConfirmed = false;
      this.dialogOrigin?.focus();
    },
    invalidateCatalog() {
      ++this.catalogSequence;
      this.catalog = [];
      this.catalogResultQuery = null;
      this.catalogResultSupplier = null;
      this.catalogSelection = "";
      this.equivalentConfirmed = false;
      this.catalogLoading = false;
      this.catalogError = "";
    },
    async searchCatalog(page = 1) {
      if (
        this.destroyed ||
        (this.supplierDialogEpoch > 0 && !this.supplierDialogOpen)
      )
        return;
      this.invalidateCatalog();
      const query = this.catalogQuery,
        supplier = this.catalogSupplier;
      const id = ++this.catalogSequence;
      this.catalogLoading = true;
      this.catalogError = "";
      this.catalogSelection = "";
      const params = new URLSearchParams({
        q: this.catalogQuery,
        supplier_id: this.catalogSupplier,
        page,
      });
      try {
        const result = await this.request(
          `${this.workspace.urls.catalog_search}?${params}`,
          { method: "GET" }
        );
        if (
          this.destroyed ||
          id !== this.catalogSequence ||
          query !== this.catalogQuery ||
          supplier !== this.catalogSupplier
        )
          return;
        this.catalog = result.items ?? [];
        this.catalogResultQuery = query;
        this.catalogResultSupplier = supplier;
        this.catalogPage = result.pagination.current_page;
        this.catalogLastPage = result.pagination.last_page;
      } catch (error) {
        if (
          !this.destroyed &&
          id === this.catalogSequence &&
          query === this.catalogQuery &&
          supplier === this.catalogSupplier
        ) {
          this.catalog = [];
          this.catalogError = error.message;
        }
      } finally {
        if (!this.destroyed && id === this.catalogSequence)
          this.catalogLoading = false;
      }
    },
    async attachSupplier() {
      const epoch = this.supplierDialogEpoch;
      if (
        this.catalogQuery !== this.catalogResultQuery ||
        this.catalogSupplier !== this.catalogResultSupplier
      ) {
        this.invalidateCatalog();
        return false;
      }
      const item = this.catalog.find(
        (row) => String(row.id) === String(this.catalogSelection)
      );
      if (
        !item ||
        !this.equivalentConfirmed ||
        this.mutationBusy() ||
        this.catalogLoading
      )
        return false;
      const result = await this.mutateSupplier(item.map_url, {
        product_id: this.workspace.product.id,
        supplier_version: item.supplier_version,
        version: item.version ?? 0,
        priority: this.workspace.sources.length + 1,
        is_active: 1,
        equivalent_confirmed: 1,
      });
      if (result && !this.destroyed && epoch === this.supplierDialogEpoch)
        this.closeSupplierDialog();
      return result;
    },
    invalidatePreview() {
      ++this.previewSequence;
      this.preview = null;
      this.previewLoading = false;
      this.feedback.preview = "";
    },
    async previewPrice() {
      if (this.destroyed) return;
      const inputs = JSON.stringify([
        this.draft.policy,
        this.previewCatalogId,
        this.workspace.policy.version,
      ]);
      const id = ++this.previewSequence;
      this.previewLoading = true;
      this.preview = null;
      this.feedback.preview = "";
      try {
        const body = {
          ...this.draft.policy,
          markup_basis_points: percentToBasisPoints(
            this.draft.policy.markup_percent
          ),
          catalog_item_id: this.previewCatalogId,
          version: this.workspace.policy.version,
        };
        delete body.markup_percent;
        const result = await this.request(this.workspace.urls.price_preview, {
          body,
        });
        if (
          !this.destroyed &&
          id === this.previewSequence &&
          inputs ===
            JSON.stringify([
              this.draft.policy,
              this.previewCatalogId,
              this.workspace.policy.version,
            ])
        )
          this.preview = result.preview ?? result;
      } catch (error) {
        if (
          !this.destroyed &&
          id === this.previewSequence &&
          inputs ===
            JSON.stringify([
              this.draft.policy,
              this.previewCatalogId,
              this.workspace.policy.version,
            ])
        )
          this.feedback.preview = error.message;
      } finally {
        if (!this.destroyed && id === this.previewSequence)
          this.previewLoading = false;
      }
    },
  };
}

export function workspaceStock(
  payload,
  { fetchRequest = (...args) => fetch(...args) } = {}
) {
  const dashboard = adminDashboard({
    activeTab: "produk",
    structuredStockInputEnabled: payload.structured_stock_input_enabled,
  });
  const originalInit = dashboard.init;
  const originalCloseStock = dashboard.closeStockModal;
  const originalDestroy = dashboard.destroy;
  const originalSubmitAccounts = dashboard.submitAccountInput;
  const originalDeleteSelected = dashboard.deleteSelectedManageStock;
  const originalLoadManageStock = dashboard.loadManageStockPayload;
  return {
    ...dashboard,
    workspaceStockPayload: {
      ...payload.edit_payload,
      ...payload.stock_payload,
    },
    workspaceStockMessage: "",
    workspaceStockBusy: false,
    workspaceStockDestroyed: false,
    init() {
      originalInit.call(this);
      this.$watch?.("stockItems", () => this.emitStockDirty());
      this.$watch?.("accountCards", () => this.emitStockDirty());
      this.$watch?.("manageStockItems", () => this.emitStockDirty());
      this.$watch?.("manageStockDeleteMessage", (message) => {
        if (message) this.stockSaved({ message });
      });
    },
    destroy() {
      this.workspaceStockDestroyed = true;
      ++this.manageStockRequestId;
      ++this.accountInputDraftRevision;
      ++this.accountInputReviewRequestId;
      this.stockItems = "";
      this.stockOperationKey = "";
      this.accountCards = [];
      this.accountInputReview = null;
      this.accountInputOperationKey = "";
      this.manageStockItems = [];
      this.manageStockLoadPayload = null;
      this.manageStockAddPayload = null;
      this.manageStockInitialPayload = null;
      originalDestroy.call(this);
    },
    beginStockMutation() {
      if (
        this.workspaceStockDestroyed ||
        this.workspaceStockBusy ||
        this.mutationBusy?.()
      )
        return false;
      this.workspaceStockBusy = true;
      this.$dispatch?.("workspace-stock-busy", { busy: true });
      return true;
    },
    endStockMutation() {
      if (this.workspaceStockDestroyed) return;
      this.workspaceStockBusy = false;
      this.$dispatch?.("workspace-stock-busy", { busy: false });
    },
    async loadManageStockPayload(product, editingItemId = null) {
      if (this.workspaceStockDestroyed) return;
      return originalLoadManageStock.call(this, product, editingItemId);
    },
    async submitAccountInput(event) {
      if (!this.accountInputCanCommit())
        return originalSubmitAccounts.call(this, event);
      event.preventDefault();
      if (!this.beginStockMutation()) return;
      try {
        await originalSubmitAccounts.call(this, event);
      } finally {
        this.endStockMutation();
      }
    },
    async deleteSelectedManageStock() {
      if (!this.beginStockMutation()) return;
      try {
        await originalDeleteSelected.call(this);
      } finally {
        this.endStockMutation();
      }
    },
    closeStockModal(force = false) {
      if (this.workspaceStockBusy && !force) return;
      originalCloseStock.call(this, force);
    },
    emitStockDirty() {
      if (this.workspaceStockDestroyed) return;
      const dirty =
        Boolean(this.stockItems?.trim()) ||
        Boolean(
          this.accountCards?.some(
            (card) => card.email || card.password || card.accessValue
          )
        ) ||
        Boolean(this.manageStockItems?.some((item) => item.editing));
      this.$dispatch?.("workspace-stock-dirty", { dirty });
    },
    async stockSaved(body) {
      if (this.workspaceStockDestroyed) return;
      this.workspaceStockMessage = body.message || "Stok tersimpan.";
      this.$dispatch?.("workspace-stock-saved", body);
      this.emitStockDirty();
    },
    async onStockSaved(body) {
      await this.stockSaved(body);
    },
    async submitStockForm(event) {
      event.preventDefault();
      if (!event.target.checkValidity() || this.stockFormSubmitting) return;
      this.stockFormSubmitting = true;
      try {
        await this.submitWorkspaceStockForm(event.target, true);
      } finally {
        if (!this.workspaceStockDestroyed) this.stockFormSubmitting = false;
      }
    },
    async submitWorkspaceStockForm(form, intake = false) {
      if (!this.beginStockMutation()) return;
      this.manageStockDeleting = !intake;
      this.workspaceStockMessage = "";
      this.manageStockErrors = {};
      try {
        const data = new FormData(form);
        data.set("return_url", this.workspaceStockPayload.stockReturnUrl ?? "");
        const response = await fetchRequest(form.action, {
          method: "POST",
          credentials: "same-origin",
          headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf() },
          body: data,
        });
        const body = await response.json().catch(() => ({}));
        if (this.workspaceStockDestroyed) return;
        if (!response.ok || response.redirected) {
          this.manageStockErrors = body.errors ?? {};
          throw new Error(
            body.message || "Stok belum tersimpan. Isian tetap ada."
          );
        }
        if (intake) this.closeStockModal(true);
        else await this.retryManageStockLoad();
        await this.stockSaved(body);
      } catch (error) {
        if (this.workspaceStockDestroyed) return;
        this.workspaceStockMessage =
          error.message || "Koneksi gagal. Isian tetap ada.";
        this.$nextTick?.(() =>
          intake
            ? this.$refs?.stockItems?.focus()
            : this.focusManageStockError()
        );
      } finally {
        if (!this.workspaceStockDestroyed) {
          this.manageStockDeleting = false;
          this.endStockMutation();
        }
      }
    },
    captureStockSubmit(event) {
      const form = event.target;
      if (
        event.defaultPrevented ||
        form.closest("#stock-input-account-panel") ||
        form.querySelector('[name="stock_items"]')
      )
        return;
      event.preventDefault();
      this.submitWorkspaceStockForm(form);
    },
  };
}
