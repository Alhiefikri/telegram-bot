const cloneFamilies = (families) =>
  families.map((family) => ({
    ...family,
    edit_payload: { ...family.edit_payload },
  }));

export default function familyOrdering(initialState = {}) {
  const families = cloneFamilies(initialState.familyOrder ?? []);
  return {
    familyOrder: families,
    familyOrderSaved: cloneFamilies(families),
    familyReorderUrl: initialState.familyReorderUrl ?? "",
    familyOrderSaving: false,
    familyOrderError: "",
    familyOrderMessage: "",
    familyOrderStale: false,
    familyMoveId: "",
    familyMoveAfterId: "",

    isFamilyOrderDirty() {
      return this.familyOrder.some(
        (family, index) => family.id !== this.familyOrderSaved[index]?.id
      );
    },

    familyOrderMoveTargets() {
      return this.familyOrder.filter(
        (family) => String(family.id) !== this.familyMoveId
      );
    },

    moveFamily(id, offset) {
      if (
        this.familyOrderSaving ||
        this.familyFormSubmitting ||
        this.familyOrderStale
      )
        return;
      const index = this.familyOrder.findIndex((family) => family.id === id);
      const target = index + offset;
      if (index < 0 || target < 0 || target >= this.familyOrder.length) return;
      const [family] = this.familyOrder.splice(index, 1);
      this.familyOrder.splice(target, 0, family);
      this.familyOrderMessage = `${family.name} dipindah ke posisi ${target + 1}. Belum disimpan.`;
      this.familyOrderError = "";
      this.$nextTick(() => {
        const row = document.getElementById(`admin-product-family-${id}`);
        const direction =
          target === 0
            ? "down"
            : target === this.familyOrder.length - 1
              ? "up"
              : offset < 0
                ? "up"
                : "down";
        row?.querySelector(`[data-family-move="${direction}"]`)?.focus();
      });
    },

    moveFamilyAfter() {
      if (
        this.familyOrderSaving ||
        this.familyFormSubmitting ||
        this.familyOrderStale
      )
        return;
      const index = this.familyOrder.findIndex(
        (family) => String(family.id) === this.familyMoveId
      );
      if (index < 0 || this.familyMoveAfterId === this.familyMoveId) return;
      if (
        this.familyMoveAfterId !== "" &&
        !this.familyOrder.some(
          (family) => String(family.id) === this.familyMoveAfterId
        )
      )
        return;
      const [family] = this.familyOrder.splice(index, 1);
      const target =
        this.familyMoveAfterId === ""
          ? 0
          : this.familyOrder.findIndex(
              (item) => String(item.id) === this.familyMoveAfterId
            ) + 1;
      this.familyOrder.splice(target, 0, family);
      this.familyOrderMessage = `${family.name} dipindah ke posisi ${target + 1}. Belum disimpan.`;
      this.familyOrderError = "";
    },

    resetFamilyOrder() {
      if (this.familyOrderSaving) return;
      this.familyOrder = cloneFamilies(this.familyOrderSaved);
      this.familyOrderMessage =
        "Draf dibatalkan. Daftar kembali ke urutan terakhir yang dikonfirmasi.";
      this.familyOrderError = this.familyOrderStale
        ? "Daftar family telah berubah. Muat ulang halaman sebelum menyimpan lagi."
        : "";
    },

    async saveFamilyOrder() {
      if (
        !this.familyOrderDirty ||
        this.familyOrderSaving ||
        this.familyFormSubmitting ||
        this.familyOrderStale
      )
        return;
      this.familyOrderSaving = true;
      this.familyOrderError = "";
      this.familyOrderMessage = "Menyimpan urutan family...";
      try {
        const response = await fetch(this.familyReorderUrl, {
          method: "PATCH",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN":
              document.querySelector(
                '#admin-product-family-management input[name="_token"]'
              )?.value ?? "",
          },
          body: JSON.stringify({
            family_ids: this.familyOrder.map((family) => family.id),
            expected_order: this.familyOrderSaved.map((family) => ({
              id: family.id,
              sort_order: family.sort_order,
            })),
          }),
        });
        const payload = await response.json().catch(() => ({}));
        if (response.status === 409) this.familyOrderStale = true;
        if (!response.ok || response.redirected) {
          throw new Error(
            response.status === 409
              ? "Daftar family telah berubah. Muat ulang halaman sebelum menyimpan lagi."
              : response.status === 419 ||
                  response.status === 401 ||
                  response.redirected
                ? "Sesi berakhir. Muat ulang halaman dan masuk kembali sebelum mencoba lagi."
                : response.status === 403
                  ? "Anda tidak memiliki izin mengatur urutan family."
                  : "Urutan belum terkonfirmasi tersimpan. Coba simpan lagi atau muat ulang untuk memeriksa urutan server."
          );
        }
        if (
          !Array.isArray(payload.families) ||
          payload.families.length !== this.familyOrder.length ||
          payload.families.some(
            (family, index) =>
              family.id !== this.familyOrder[index].id ||
              family.sort_order !== index
          )
        ) {
          throw new Error(
            "Jawaban server tidak sesuai. Muat ulang untuk memeriksa urutan tersimpan."
          );
        }
        this.familyOrder.forEach((family, index) => {
          family.sort_order = index;
          family.edit_payload.sortOrder = index;
        });
        this.familyOrderSaved = cloneFamilies(this.familyOrder);
        if (this.familyFormMode === "edit") {
          this.familySortOrder =
            this.familyOrder.find((family) => family.id === this.familyId)
              ?.sort_order ?? this.familySortOrder;
        }
        this.familyOrderMessage = payload.message;
      } catch (error) {
        this.familyOrderMessage = "";
        this.familyOrderError =
          error.message === "Failed to fetch"
            ? "Koneksi terputus. Status penyimpanan belum diketahui. Coba simpan lagi atau muat ulang untuk memeriksa urutan server."
            : error.message;
      } finally {
        this.familyOrderSaving = false;
      }
    },
  };
}
