export default function supplierConnectionForm(
  provider,
  providers,
  confirmationUrl
) {
  return {
    provider,
    providers,
    saving: false,
    confirming: false,
    error: "",
    confirmationPassword: "",
    confirmationError: "",

    init() {
      this.$watch("provider", (next, previous) => {
        if (next === previous) return;
        if (next === "buymail") {
          this.$refs.exchangeRate.value = "1";
        } else if (
          this.providers[next].currency !== this.providers[previous].currency ||
          this.providers[next].confirm ||
          this.providers[previous].confirm
        ) {
          this.clearExchangeRate();
        }
      });
    },

    clearExchangeRate() {
      this.$refs.exchangeRate.value = "";
    },

    async save() {
      const form = this.$refs.connectionForm;
      if (this.saving || this.confirming || !form.reportValidity()) return;
      this.saving = true;
      this.error = "";
      try {
        const response = await fetch(form.action, {
          method: "POST",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
          body: new FormData(form),
        });
        const result = await response.json().catch(() => ({}));
        if (response.status === 428) {
          this.confirmationPassword = "";
          this.confirmationError = "";
          this.$refs.passwordDialog.showModal();
          this.$nextTick(() => this.$refs.confirmationPassword.focus());
        } else if (response.ok && result.redirect_url) {
          window.location.assign(result.redirect_url);
        } else {
          this.error =
            result.message ||
            "Supplier belum tersimpan. Periksa isian lalu coba lagi.";
        }
      } catch {
        this.error =
          "Koneksi terputus. Isianmu tetap ada. Periksa daftar supplier sebelum mencoba menyimpan lagi.";
      } finally {
        this.saving = false;
      }
    },

    async confirmPassword() {
      if (this.confirming) return;
      if (!this.confirmationPassword) {
        this.confirmationError = "Isi password admin terlebih dahulu.";
        this.$refs.confirmationPassword.focus();
        return;
      }
      this.confirming = true;
      this.confirmationError = "";
      let confirmed = false;
      try {
        const response = await fetch(confirmationUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
          body: new URLSearchParams({
            _token:
              this.$refs.connectionForm.querySelector('[name="_token"]').value,
            password: this.confirmationPassword,
          }),
        });
        const result = await response.json().catch(() => ({}));
        if (response.ok && result.confirmed === true) {
          confirmed = true;
          this.$refs.passwordDialog.close();
        } else {
          this.confirmationError =
            result.errors?.password?.[0] ||
            (response.status === 429
              ? "Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi."
              : "Password belum terkonfirmasi. Coba lagi.");
        }
      } catch {
        this.confirmationError =
          "Koneksi terputus. Coba konfirmasi lagi; isian supplier tetap ada.";
      } finally {
        this.confirmationPassword = "";
        this.confirming = false;
      }
      if (confirmed) await this.save();
    },

    cancelConfirmation() {
      if (this.confirming) return;
      this.confirmationPassword = "";
      this.confirmationError = "";
      this.$refs.passwordDialog.close();
      this.$refs.saveButton.focus();
    },
  };
}
