import { rateFromDeposit } from "./supplier-connection-form.js";

const decimalFormat = new Intl.NumberFormat("id-ID", {
  maximumFractionDigits: 6,
});

function outsideBand(rate, band) {
  if (!band) return false;

  return rate < Number(band.min) || rate > Number(band.max);
}

/**
 * One form for the three wallet currencies. The VND helper is the same
 * Nastele/Mirai deposit derivation used before: IDR per USDT divided by VND
 * credited per USDT, suggested into the authoritative VND rate.
 */
export default function supplierCurrencyRates(confirmationUrl, bands = {}) {
  return {
    saving: false,
    confirming: false,
    error: "",
    confirmationPassword: "",
    confirmationError: "",
    calcIdrPerUsdt: "",
    calcVndPerUsdt: "",
    calcSuggestion: "",
    calcWarning: "",
    calcError: "",

    refreshCalculator() {
      const inputs = [this.calcIdrPerUsdt, this.calcVndPerUsdt];
      if (inputs.some((value) => String(value ?? "").trim() === "")) {
        this.resetCalculator();

        return;
      }
      try {
        const rate = rateFromDeposit(...inputs);
        this.calcSuggestion = rate;
        this.calcWarning = outsideBand(Number(rate), bands.VND)
          ? "Saran kurs ini di luar rentang wajar. Periksa lagi biaya per USDT dan jumlah VND yang didapat."
          : "";
        this.calcError = "";
      } catch (error) {
        this.resetCalculator();
        this.calcError = error.message;
      }
    },

    resetCalculator() {
      this.calcSuggestion = "";
      this.calcWarning = "";
      this.calcError = "";
    },

    applyVndSuggestion() {
      if (this.calcSuggestion === "") return;
      const input = this.$refs.rateForm?.querySelector(
        '[name="rates[VND][exchange_rate_idr]"]'
      );
      if (!input) return;
      input.value = this.calcSuggestion;
      input.dispatchEvent(new Event("input", { bubbles: true }));
    },

    async save() {
      const form = this.$refs.rateForm;
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
          const firstError = result.errors
            ? Object.values(result.errors).flat().find(Boolean)
            : null;
          this.error =
            firstError ||
            result.message ||
            "Kurs belum tersimpan. Periksa isian lalu coba lagi.";
        }
      } catch {
        this.error =
          "Koneksi terputus. Isian kurs tetap ada. Periksa kembali sebelum menyimpan lagi.";
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
            _token: this.$refs.rateForm.querySelector('[name="_token"]').value,
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
          "Koneksi terputus. Coba konfirmasi lagi; isian kurs tetap ada.";
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
