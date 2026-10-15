const GROUPS_PER_PAGE = 20;
const MEMBERS_PER_PAGE = 12;
const normalizeSearch = (value) =>
  String(value ?? "")
    .trim()
    .replace(/\s+/gu, " ")
    .toLowerCase();
const matches = (values, search) =>
  values.some((value) => value.includes(search));

export default function productCatalog(config = {}) {
  const groups = Object.freeze(config.groups ?? []);
  let controller = null;
  let requestId = 0;
  let pendingProductId = null;
  let restoreLocation = null;

  return {
    search: config.search ?? "",
    sort: config.sort ?? "name_asc",
    page: config.page ?? 1,
    sortLabels: config.sortLabels ?? {},
    filteredGroups: [],
    selectedGroupId: null,
    memberPage: 1,
    detailHtml: "",
    detailLoading: false,
    detailError: "",
    ready: false,

    init() {
      this.filter(false);
      this.ready = true;
      this.revealProductHash();
      restoreLocation = () => {
        const url = new URL(window.location.href);
        this.search = url.searchParams.get("product_search") ?? "";
        this.sort = url.searchParams.get("product_sort") ?? "name_asc";
        this.page = Number(url.searchParams.get("products_page")) || 1;
        this.filter(false);
        this.revealProductHash();
      };
      window.addEventListener("popstate", restoreLocation);
      window.addEventListener("hashchange", restoreLocation);
    },

    destroy() {
      this.clearDetails();
      window.removeEventListener("popstate", restoreLocation);
      window.removeEventListener("hashchange", restoreLocation);
    },

    get pageGroups() {
      return this.filteredGroups.slice(
        (this.page - 1) * GROUPS_PER_PAGE,
        this.page * GROUPS_PER_PAGE
      );
    },

    get lastPage() {
      return Math.max(
        1,
        Math.ceil(this.filteredGroups.length / GROUPS_PER_PAGE)
      );
    },

    get pages() {
      const start = Math.max(1, Math.min(this.page - 2, this.lastPage - 4));
      return Array.from(
        { length: Math.min(5, this.lastPage) },
        (_, index) => start + index
      );
    },

    filter(resetPage = true) {
      const search = normalizeSearch(this.search);
      if (!Object.hasOwn(this.sortLabels, this.sort)) this.sort = "name_asc";
      this.filteredGroups = groups
        .filter(
          (group) =>
            !search ||
            matches(group.search, search) ||
            group.members.some((member) => matches(member.search, search))
        )
        .sort((left, right) => left.ranks[this.sort] - right.ranks[this.sort]);
      this.page = resetPage
        ? 1
        : Math.max(
            1,
            Math.min(this.lastPage, Math.floor(Number(this.page)) || 1)
          );
      this.clearDetails();
      this.selectedGroupId = null;
      if (resetPage) {
        this.syncUrl();
        if (search && this.pageGroups.length)
          this.openGroup(this.pageGroups[0]);
      }
    },

    reset() {
      this.search = "";
      this.sort = "name_asc";
      this.filter();
    },

    goToPage(page) {
      this.page = Math.max(1, Math.min(this.lastPage, Number(page) || 1));
      this.selectedGroupId = null;
      this.clearDetails();
      this.syncUrl();
    },

    syncUrl() {
      const url = new URL(window.location.href);
      url.searchParams.set("tab", "produk");
      const search = normalizeSearch(this.search);
      if (search) url.searchParams.set("product_search", search);
      else url.searchParams.delete("product_search");
      if (this.sort !== "name_asc")
        url.searchParams.set("product_sort", this.sort);
      else url.searchParams.delete("product_sort");
      if (this.page > 1) url.searchParams.set("products_page", this.page);
      else url.searchParams.delete("products_page");
      url.hash = "admin-product-families";
      window.history.replaceState(window.history.state, "", url);
    },

    orderedMembers(group) {
      const search = normalizeSearch(this.search);
      if (!search) return group.members;
      return [
        ...group.members.filter((member) => matches(member.search, search)),
        ...group.members.filter((member) => !matches(member.search, search)),
      ];
    },

    memberLastPage(group) {
      return Math.max(1, Math.ceil(group.members.length / MEMBERS_PER_PAGE));
    },

    toggleGroup(group) {
      if (this.selectedGroupId === group.id) {
        this.selectedGroupId = null;
        this.clearDetails();
      } else this.openGroup(group);
    },

    openGroup(group, memberPage = 1) {
      this.clearDetails();
      this.selectedGroupId = group.id;
      this.memberPage = Math.max(
        1,
        Math.min(this.memberLastPage(group), memberPage)
      );
      this.loadDetails(group);
    },

    clearDetails() {
      ++requestId;
      controller?.abort();
      controller = null;
      pendingProductId = null;
      this.detailHtml = "";
      this.detailError = "";
      this.detailLoading = false;
    },

    async loadDetails(group) {
      controller?.abort();
      const currentRequest = ++requestId;
      controller = new AbortController();
      const members = this.orderedMembers(group).slice(
        (this.memberPage - 1) * MEMBERS_PER_PAGE,
        this.memberPage * MEMBERS_PER_PAGE
      );
      this.detailHtml = "";
      this.detailError = "";
      this.detailLoading = members.length > 0;
      if (!members.length) return;
      const url = new URL(config.cardsUrl, window.location.origin);
      members.forEach((member) => url.searchParams.append("ids[]", member.id));
      try {
        const response = await fetch(url, {
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          credentials: "same-origin",
          signal: controller.signal,
        });
        if (!response.ok) throw new Error("catalog_cards_failed");
        const result = await response.json();
        if (currentRequest !== requestId) return;
        if (typeof result.html !== "string" || !result.html.trim())
          throw new Error("catalog_cards_invalid");
        this.detailHtml = result.html;
        if (pendingProductId !== null) {
          const id = pendingProductId;
          pendingProductId = null;
          this.$nextTick(() => {
            const product = document.getElementById(`product-${id}`);
            product?.scrollIntoView({ block: "center" });
            product?.focus({ preventScroll: true });
          });
        }
      } catch (error) {
        if (currentRequest !== requestId || error.name === "AbortError") return;
        this.detailError =
          "Daftar product gagal dimuat. Coba lagi atau buka halaman kelola produk.";
      } finally {
        if (currentRequest === requestId) this.detailLoading = false;
      }
    },

    revealProductHash() {
      const match = /^#product-(\d+)$/.exec(window.location.hash);
      if (!match) return;
      const id = Number(match[1]);
      const group = groups.find((item) =>
        item.members.some((member) => member.id === id)
      );
      if (!group) return;
      if (!this.filteredGroups.some((item) => item.id === group.id)) {
        this.search = "";
        this.filter(false);
      }
      this.page =
        Math.floor(
          this.filteredGroups.findIndex((item) => item.id === group.id) /
            GROUPS_PER_PAGE
        ) + 1;
      const memberPage =
        Math.floor(
          this.orderedMembers(group).findIndex((member) => member.id === id) /
            MEMBERS_PER_PAGE
        ) + 1;
      this.openGroup(group, memberPage);
      pendingProductId = id;
    },

    workspaceUrl(id) {
      return config.workspaceUrl.replace("__PRODUCT__", Number(id));
    },
  };
}
